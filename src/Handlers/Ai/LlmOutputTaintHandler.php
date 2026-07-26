<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Ai;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\TaintKind;
use Psalm\Type\Union;

/**
 * Marks reads of LLM-controlled data on Laravel AI objects as an `input` taint
 * source. The model's output is downstream of every untrusted source that
 * reached its prompt (indirect prompt injection — attacker content in a web
 * page, RAG corpus, tool output, or email), so passing it unsanitized to SQL,
 * shell, HTML, header, or filesystem sinks should fire the matching `Tainted*`
 * issue.
 *
 * Two read shapes need a handler because a docblock annotation cannot express
 * either of them:
 *
 * 1. `$response->text` — Psalm's `@psalm-taint-source` is not honored on
 *    properties, only on method return types.
 * 2. `$response['field']` / `$request['task']` — `ArrayFetchAnalyzer`
 *    synthesizes the `offsetGet()` call in a cloned node-data set and copies
 *    back only the return type, so the taint edge from the stub's annotation on
 *    `offsetGet()` is discarded (same upstream gap as #1304). An explicit
 *    `$response->offsetGet('field')` call does carry the stub annotation.
 *
 * Both are handled by re-sourcing at the read site, which also sidesteps the
 * upstream "property taint never flows" bug. The stubs complement this with
 * `@psalm-taint-source` on `__toString()`, `toArray()` and friends.
 *
 * Property-read coverage:
 * - `Laravel\Ai\Responses\TextResponse` (and subclasses, including
 *   `AgentResponse`, `StructuredAgentResponse`, `StructuredTextResponse` and
 *   `StreamedAgentResponse` — the snapshot returned to
 *   `Promptable::stream()->then()` callbacks).
 * - `Laravel\Ai\Responses\StreamableAgentResponse` (separate hierarchy in
 *   the real package — `$text` is populated after the stream completes).
 * - `Laravel\Ai\Responses\TranscriptionResponse` (own hierarchy; a transcript
 *   of user-supplied audio is attacker-authored text a speech model re-typed).
 *
 * Array-read coverage:
 * - `Laravel\Ai\Tools\Request` — the arguments the model chose for a tool call.
 *   This is the shape `Tools\AgentTool::handle()` itself uses to forward a task
 *   into a sub-agent's prompt.
 * - `Laravel\Ai\Responses\StructuredAgentResponse` /
 *   `StructuredTextResponse` — the decoded structured payload. The keys come
 *   from the application's schema, the values do not.
 *
 * @see https://genai.owasp.org/llmrisk/llm01-prompt-injection/ OWASP LLM01:2025
 * @see https://github.com/laravel/ai Laravel AI SDK
 *
 * @psalm-api
 *
 * @internal
 */
final class LlmOutputTaintHandler implements AfterExpressionAnalysisInterface
{
    /**
     * Classes whose `$text` property carries LLM-generated (untrusted) data.
     * Subclasses are still covered via `classExtendsOrImplements`; the explicit
     * list shortcuts the common case to a single `in_array()` check.
     *
     * @var list<string>
     */
    private const TAINTED_CLASSES = [
        'Laravel\\Ai\\Responses\\TextResponse',
        'Laravel\\Ai\\Responses\\AgentResponse',
        'Laravel\\Ai\\Responses\\StreamedAgentResponse',
        'Laravel\\Ai\\Responses\\StreamableAgentResponse',
        'Laravel\\Ai\\Responses\\TranscriptionResponse',
    ];

    /**
     * Properties on the classes above that contain LLM-generated content.
     *
     * @var list<string>
     */
    private const TAINTED_PROPERTIES = ['text'];

    /**
     * Classes whose array-access reads return LLM-controlled data.
     *
     * @var list<string>
     */
    private const ARRAY_ACCESS_TAINTED_CLASSES = [
        'Laravel\\Ai\\Tools\\Request',
        'Laravel\\Ai\\Responses\\StructuredAgentResponse',
        'Laravel\\Ai\\Responses\\StructuredTextResponse',
    ];

    /** @inheritDoc */
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $codebase = $event->getCodebase();

        // Pure performance gate: taint analysis is off → do nothing. Saves the per-expression
        // type lookup on every Psalm run that doesn't pass --taint-analysis.
        if (!$codebase->taint_flow_graph instanceof \Psalm\Internal\Codebase\TaintFlowGraph) {
            return null;
        }

        $expr = $event->getExpr();

        if ($expr instanceof PropertyFetch) {
            if (!$expr->name instanceof Identifier) {
                return null;
            }

            if (!\in_array($expr->name->name, self::TAINTED_PROPERTIES, true)) {
                return null;
            }

            self::taintRead($event, $expr, $expr->var, self::TAINTED_CLASSES, 'llm-output-' . $expr->name->name);

            return null;
        }

        if ($expr instanceof ArrayDimFetch) {
            // A null dim is `$x[] = ...`, always a write target.
            if (!$expr->dim instanceof Expr) {
                return null;
            }

            self::taintRead($event, $expr, $expr->var, self::ARRAY_ACCESS_TAINTED_CLASSES, 'llm-output-offset');
        }

        return null;
    }

    /**
     * @param list<string> $taintedClasses
     */
    private static function taintRead(
        AfterExpressionAnalysisEvent $event,
        Expr $expr,
        Expr $receiver,
        array $taintedClasses,
        string $taintIdPrefix,
    ): void {
        $source = $event->getStatementsSource();
        $nodeTypeProvider = $source->getNodeTypeProvider();

        $receiverType = $nodeTypeProvider->getType($receiver);

        if (!$receiverType instanceof Union) {
            return;
        }

        if (!self::isLlmSurface($receiverType, $taintedClasses, $event->getCodebase())) {
            return;
        }

        self::addSource($event, $expr, $source, $taintIdPrefix);
    }

    /**
     * @param list<string> $taintedClasses
     *
     * @psalm-external-mutation-free
     */
    private static function isLlmSurface(Union $receiverType, array $taintedClasses, Codebase $codebase): bool
    {
        foreach ($receiverType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject) {
                continue;
            }

            if (\in_array($atomic->value, $taintedClasses, true)) {
                return true;
            }

            // Cover user-defined subclasses (e.g. a project's own response
            // wrapper extending AgentResponse).
            if (!$codebase->classExists($atomic->value)) {
                continue;
            }

            foreach ($taintedClasses as $taintedClass) {
                if (!$codebase->classExists($taintedClass)) {
                    continue;
                }

                if ($codebase->classExtendsOrImplements($atomic->value, $taintedClass)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function addSource(
        AfterExpressionAnalysisEvent $event,
        Expr $expr,
        StatementsSource $source,
        string $taintIdPrefix,
    ): void {
        $nodeTypeProvider = $source->getNodeTypeProvider();

        // The expression type may be unset when Psalm couldn't resolve the member —
        // fall back to `string`, the type every covered read ultimately carries into
        // a sink, so the taint annotation survives.
        $exprType = $nodeTypeProvider->getType($expr) ?? Type::getString();

        $taintId = $taintIdPrefix
            . '-' . $source->getFileName()
            . ':' . $expr->getStartFilePos();

        $taintedType = $event->getCodebase()->addTaintSource(
            $exprType,
            $taintId,
            TaintKind::ALL_INPUT,
            new CodeLocation($source, $expr),
        );

        $nodeTypeProvider->setType($expr, $taintedType);
    }
}
