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
 * Marks LLM response properties as taint sources.
 *
 * Psalm's `@psalm-taint-source` annotation does not work on properties,
 * only on method return types. This handler bridges that gap by intercepting
 * property reads on `TextResponse::$text` (and subclasses) and adding the
 * `input` taint source programmatically via `Codebase::addTaintSource()`.
 *
 * This makes `$response->text` tainted, catching:
 * - `echo $response->text` → TaintedHtml
 * - `DB::raw($response->text)` → TaintedSql
 * - `Process::run($response->text)` → TaintedShell
 *
 * @see https://genai.owasp.org/llmrisk/llm01-prompt-injection/ OWASP LLM01:2025
 *
 * @psalm-api
 * @internal
 */
final class LlmOutputTaintHandler implements AfterExpressionAnalysisInterface
{
    /**
     * Classes whose $text property carries LLM-generated (untrusted) data.
     * AgentResponse extends TextResponse and inherits the $text property.
     * StreamableAgentResponse does NOT extend TextResponse but also has
     * a $text property populated after streaming completes.
     *
     * StructuredAgentResponse is intentionally excluded here because its
     * primary attack surface is array access ($response['field']),
     * not the $text property — that should be handled via stub taint
     * annotations on offsetGet()/toArray() in a future iteration.
     */
    private const TAINTED_CLASSES = [
        'Laravel\\Ai\\Responses\\TextResponse',
        'Laravel\\Ai\\Responses\\AgentResponse',
        'Laravel\\Ai\\Responses\\StreamableAgentResponse',
    ];

    /** Properties that contain LLM-generated content */
    private const TAINTED_PROPERTIES = ['text'];

    /** @inheritDoc */
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $codebase = $event->getCodebase();

        // This handler only adds taint sources; skip entirely when taint analysis is off
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

        // Check if the object being accessed is a TextResponse (or subclass)
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

            // Also check parent classes for inheritance
            if ($codebase->classExists($atomic->value)) {
                foreach (self::TAINTED_CLASSES as $taintedClass) {
                    if ($codebase->classExists($taintedClass)
                        && $codebase->classExtendsOrImplements($atomic->value, $taintedClass)
                    ) {
                        $isLlmResponse = true;
                        break 2;
                    }
                }
            }
        }

        if (!$isLlmResponse) {
            return null;
        }

        // Get the current type of the property expression and add taint.
        // Fall back to string if Psalm couldn't resolve the type — we know
        // $text is always string, and the taint annotation must not be lost.
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
