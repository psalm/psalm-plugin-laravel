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
 * Marks the `$text` property on Laravel AI response objects as an `input`
 * taint source. The model's output is downstream of every untrusted source
 * that reached its prompt (indirect prompt injection — attacker content in
 * a web page, RAG corpus, tool output, or email), so passing it unsanitized
 * to SQL, shell, HTML, header, or filesystem sinks should fire the matching
 * `Tainted*` issue.
 *
 * Psalm's `@psalm-taint-source` docblock annotation is not honored on
 * properties, only on method return types. This handler bridges that gap by
 * intercepting reads of the property and adding the taint via
 * `Codebase::addTaintSource()`. The stub at
 * `stubs/integrations/laravel-ai/Responses/TextResponse.phpstub`
 * complements this with `@psalm-taint-source` on `__toString()`.
 *
 * Covered:
 * - `Laravel\Ai\Responses\TextResponse` (and subclasses, including
 *   `AgentResponse`).
 * - `Laravel\Ai\Responses\StreamableAgentResponse` (separate hierarchy in
 *   the real package — `$text` is populated after the stream completes).
 *
 * `StructuredAgentResponse` is intentionally left out: its primary surface
 * is array access (`$response['field']`), not the `$text` property — that
 * belongs in a stub for `offsetGet()`/`toArray()`.
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
     *
     * @var list<string>
     */
    private const TAINTED_CLASSES = [
        'Laravel\\Ai\\Responses\\TextResponse',
        'Laravel\\Ai\\Responses\\AgentResponse',
        'Laravel\\Ai\\Responses\\StreamableAgentResponse',
    ];

    /**
     * Properties on the classes above that contain LLM-generated content.
     *
     * @var list<string>
     */
    private const TAINTED_PROPERTIES = ['text'];

    /** @inheritDoc */
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $codebase = $event->getCodebase();

        // Pure performance gate: taint analysis is off → do nothing. Saves the per-expression
        // type lookup on every Psalm run that doesn't pass --taint-analysis.
        if ($codebase->taint_flow_graph === null) {
            return null;
        }

        $expr = $event->getExpr();

        if (!$expr instanceof PropertyFetch) {
            return null;
        }

        if (!$expr->name instanceof Identifier) {
            return null;
        }

        if (!\in_array($expr->name->name, self::TAINTED_PROPERTIES, true)) {
            return null;
        }

        $source = $event->getStatementsSource();
        $nodeTypeProvider = $source->getNodeTypeProvider();

        $varType = $nodeTypeProvider->getType($expr->var);

        if ($varType === null) {
            return null;
        }

        $isLlmResponse = false;

        foreach ($varType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject) {
                continue;
            }

            if (\in_array($atomic->value, self::TAINTED_CLASSES, true)) {
                $isLlmResponse = true;
                break;
            }

            // Cover user-defined subclasses (e.g. a project's own response
            // wrapper extending AgentResponse).
            if ($codebase->classExists($atomic->value)) {
                foreach (self::TAINTED_CLASSES as $taintedClass) {
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
        // fall back to `string` so the taint annotation survives. `$text` is always
        // a string in the laravel/ai package.
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
