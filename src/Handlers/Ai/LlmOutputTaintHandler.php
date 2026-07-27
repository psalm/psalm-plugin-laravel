<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Ai;

use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use Psalm\CodeLocation;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\TaintKind;

/**
 * Marks the properties on Laravel AI response objects that hold model output as
 * an `input` taint source. The model's output is downstream of every untrusted
 * source that reached its prompt (indirect prompt injection — attacker content
 * in a web page, RAG corpus, tool output, or email), so passing it unsanitized
 * to SQL, shell, HTML, header, or filesystem sinks should fire the matching
 * `Tainted*` issue.
 *
 * Psalm's `@psalm-taint-source` docblock annotation is not honored on
 * properties, only on method return types. This handler bridges that gap by
 * intercepting reads of the property and adding the taint via
 * `Codebase::addTaintSource()`. The response stubs under
 * `stubs/integrations/laravel-ai/Responses/` complement it with
 * `@psalm-taint-source` on `__toString()` and the payload accessors, which are
 * method returns and so can be annotated declaratively.
 *
 * `$text` is covered on:
 * - `Laravel\Ai\Responses\TextResponse` (and subclasses, including
 *   `AgentResponse` and `StreamedAgentResponse` — the snapshot returned to
 *   `Promptable::stream()->then()` callbacks).
 * - `Laravel\Ai\Responses\StreamableAgentResponse` (separate hierarchy in
 *   the real package — `$text` is populated after the stream completes).
 * - `Laravel\Ai\Responses\TranscriptionResponse` (also its own hierarchy; a
 *   transcript of user-supplied audio is attacker-authored text that a speech
 *   model merely re-typed).
 *
 * `StructuredAgentResponse` and `StructuredTextResponse` are absent from that
 * list but still covered: both inherit `$text` from `TextResponse`, so the
 * subclass walk below reaches them. They additionally expose the decoded payload
 * as a public `$structured` array, which laravel/ai's own console command reads,
 * so that property is sourced on the pair directly.
 *
 * Array-access reads (`$response['field']`, `$request['task']`) are covered by
 * nothing, here or in the stubs: Psalm discards the taint edge when it resolves
 * the `[]` sugar, so an `ArrayDimFetch` branch here would work around a core gap
 * on one of the hottest node types. Deferred to upstream
 * (https://github.com/vimeo/psalm/issues/11912) and pinned by the
 * `*KnownLimitation.phpt` fixtures. The covered paths for structured output are
 * an explicit `$response->offsetGet('field')` call and `toArray()`. Mechanism
 * and rationale: `docs/contributing/taint-analysis.md`.
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
     * Property name => classes declaring it with LLM-generated (untrusted)
     * contents. Scoped per property rather than as one class list crossed with
     * one property list, because the two properties live on different parts of
     * the hierarchy: every response carries `$text`, only the structured pair
     * carries `$structured`.
     *
     * Subclasses are still covered via `classExtendsOrImplements`; the explicit
     * lists shortcut the common case to a single `in_array()` check.
     *
     * @var array<string, list<string>>
     */
    private const TAINTED_PROPERTIES = [
        'text' => [
            'Laravel\\Ai\\Responses\\TextResponse',
            'Laravel\\Ai\\Responses\\AgentResponse',
            'Laravel\\Ai\\Responses\\StreamedAgentResponse',
            'Laravel\\Ai\\Responses\\StreamableAgentResponse',
            'Laravel\\Ai\\Responses\\TranscriptionResponse',
        ],
        // The decoded structured payload, declared by the
        // ProvidesStructuredResponse trait. A plain `array`, so reading an offset
        // off it propagates normally; the array-access gap below applies to
        // `$response['field']` on the response object, not to this.
        'structured' => [
            'Laravel\\Ai\\Responses\\StructuredAgentResponse',
            'Laravel\\Ai\\Responses\\StructuredTextResponse',
        ],
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

        if (!$expr instanceof PropertyFetch) {
            return null;
        }

        if (!$expr->name instanceof Identifier) {
            return null;
        }

        $taintedClasses = self::TAINTED_PROPERTIES[$expr->name->name] ?? null;

        if ($taintedClasses === null) {
            return null;
        }

        $source = $event->getStatementsSource();
        $nodeTypeProvider = $source->getNodeTypeProvider();

        $varType = $nodeTypeProvider->getType($expr->var);

        if (!$varType instanceof \Psalm\Type\Union) {
            return null;
        }

        $isLlmResponse = false;

        foreach ($varType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject) {
                continue;
            }

            if (\in_array($atomic->value, $taintedClasses, true)) {
                $isLlmResponse = true;
                break;
            }

            // Cover user-defined subclasses (e.g. a project's own response
            // wrapper extending AgentResponse).
            if ($codebase->classExists($atomic->value)) {
                foreach ($taintedClasses as $taintedClass) {
                    if (!$codebase->classExists($taintedClass)) {
                        continue;
                    }

                    if ($codebase->classExtendsOrImplements($atomic->value, $taintedClass)) {
                        $isLlmResponse = true;

                        break 2;
                    }
                }
            }
        }

        if (!$isLlmResponse) {
            return null;
        }

        // The expression type may be unset when Psalm couldn't resolve the property —
        // fall back to `string` so the taint annotation survives. That is the right
        // shape for `$text`; a `$structured` read that Psalm could not type is rare
        // enough that a narrower fallback is not worth a second lookup.
        $exprType = $nodeTypeProvider->getType($expr) ?? Type::getString();

        $taintId = 'llm-output-' . $expr->name->name
            . '-' . $source->getFileName()
            . ':' . $expr->getStartFilePos();

        $taintedType = $codebase->addTaintSource(
            $exprType,
            $taintId,
            TaintKind::ALL_INPUT,
            new CodeLocation($source, $expr),
        );

        $nodeTypeProvider->setType($expr, $taintedType);

        return null;
    }
}
