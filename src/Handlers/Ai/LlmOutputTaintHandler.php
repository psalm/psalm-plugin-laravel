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
 * Sources reads of the Laravel AI response properties that hold model output as
 * `input` taint. The output is downstream of everything that reached the prompt
 * (indirect prompt injection), so unsanitized flow into SQL, shell, HTML, header
 * or filesystem sinks should report the matching `Tainted*` issue.
 *
 * Psalm ignores `@psalm-taint-source` on properties, which is why this handler
 * exists; the response stubs annotate the method returns declaratively.
 *
 * Array-access reads (`$response['field']`) are not sourced here or in the
 * stubs: Psalm drops the taint edge when it resolves the `[]` sugar
 * (https://github.com/vimeo/psalm/issues/11912). Use `offsetGet()` or
 * `toArray()`; the `*KnownLimitation.phpt` fixtures pin the gap and
 * `docs/contributing/taint-analysis.md` explains it.
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
     * Property => classes declaring it with model-generated contents. Keyed per
     * property, not as classes x properties: each belongs to a different response
     * hierarchy, and a cross-product would source same-named properties on
     * unrelated classes. Subclasses (`AgentResponse`, `StructuredStep`, user
     * wrappers) match via `classExtendsOrImplements`, so only roots are listed.
     *
     * @var array<string, list<string>>
     */
    private const TAINTED_PROPERTIES = [
        'text' => [
            'Laravel\\Ai\\Responses\\TextResponse',
            'Laravel\\Ai\\Responses\\StreamableAgentResponse',
            // A transcript of user-supplied audio is attacker-authored text.
            'Laravel\\Ai\\Responses\\TranscriptionResponse',
            'Laravel\\Ai\\Responses\\Data\\Step',
            'Laravel\\Ai\\Gateway\\StepResponse',
            'Laravel\\Ai\\Responses\\Data\\TranscriptionSegment',
        ],
        // A plain `array`, so offset reads propagate; the array-access gap above
        // concerns `$response['field']` on the response object.
        'structured' => [
            'Laravel\\Ai\\Responses\\StructuredAgentResponse',
            'Laravel\\Ai\\Responses\\StructuredTextResponse',
            'Laravel\\Ai\\Responses\\Data\\StructuredStep',
            'Laravel\\Ai\\Gateway\\StepResponse',
        ],
        'reasoning' => [
            'Laravel\\Ai\\Responses\\TextResponse',
            'Laravel\\Ai\\Responses\\StreamableAgentResponse',
            'Laravel\\Ai\\Responses\\Data\\Step',
            'Laravel\\Ai\\Gateway\\StepResponse',
        ],
        // No phpt pins a flow: payloads leave only through `Collection` reads,
        // where Psalm drops the edge. Stays registered for when it doesn't.
        'citations' => [
            'Laravel\\Ai\\Responses\\StreamableAgentResponse',
        ],
        'delta' => [
            'Laravel\\Ai\\Streaming\\Events\\TextDelta',
            'Laravel\\Ai\\Streaming\\Events\\ReasoningDelta',
        ],
        // Classification answers come verbatim from the provider and are never
        // validated against the options the caller offered.
        'choice' => [
            'Laravel\\Ai\\Responses\\Data\\ChoiceAnswer',
        ],
        'legend' => [
            'Laravel\\Ai\\Responses\\Data\\ScoreAnswer',
        ],
        'answers' => [
            'Laravel\\Ai\\Responses\\ClassificationResponse',
        ],
    ];

    /** @inheritDoc */
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $codebase = $event->getCodebase();

        // Performance gate: skip the per-expression type lookup unless --taint-analysis is on.
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

            // Subclasses, including a project's own response wrapper.
            if ($codebase->classExists($atomic->value)) {
                foreach ($taintedClasses as $taintedClass) {
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

        // Unresolved property type: fall back to `string` so the taint survives.
        // Right for `$text`; a rare untyped `$structured` read is not worth a second lookup.
        $exprType = $nodeTypeProvider->getType($expr) ?? Type::getString();

        $taintId = 'llm-output-' . $expr->name->name
            . '-' . $source->getFileName()
            . ':' . $expr->getStartFilePos();

        $taintedType = $codebase->addTaintSource(
            $exprType,
            $taintId,
            new CodeLocation($source, $expr),
            TaintKind::ALL_INPUT,
        );

        $nodeTypeProvider->setType($expr, $taintedType);

        return null;
    }
}
