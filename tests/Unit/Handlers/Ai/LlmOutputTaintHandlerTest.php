<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Ai;

use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Codebase\TaintFlowGraph;
use Psalm\LaravelPlugin\Handlers\Ai\LlmOutputTaintHandler;
use Psalm\NodeTypeProvider;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Union;

/**
 * Early-exit gates of {@see LlmOutputTaintHandler}. Which classes resolve
 * to a source, and the taint flow itself, need a real Psalm analyzer and are
 * covered by the PHPT suite under `tests/Type/tests/PromptInjection/`.
 */
#[CoversClass(LlmOutputTaintHandler::class)]
final class LlmOutputTaintHandlerTest extends TestCase
{
    #[Test]
    public function it_returns_null_when_taint_analysis_is_disabled(): void
    {
        $codebase = $this->createCodebase(taintFlowGraph: null);
        $event = $this->createEvent(
            expr: $this->propertyFetch('text'),
            codebase: $codebase,
            varType: null,
        );

        $this->assertNull(LlmOutputTaintHandler::afterExpressionAnalysis($event));
    }

    #[Test]
    public function it_returns_null_for_non_property_fetch_expression(): void
    {
        $codebase = $this->createCodebase(taintFlowGraph: new TaintFlowGraph());
        $event = $this->createEvent(
            expr: new Variable('response'),
            codebase: $codebase,
            varType: Type::getString(),
        );

        $this->assertNull(LlmOutputTaintHandler::afterExpressionAnalysis($event));
    }

    #[Test]
    public function it_returns_null_for_properties_that_do_not_hold_model_output(): void
    {
        $codebase = $this->createCodebase(taintFlowGraph: new TaintFlowGraph());
        $event = $this->createEvent(
            expr: $this->propertyFetch('usage'),
            codebase: $codebase,
            varType: $this->namedObjectType('Laravel\\Ai\\Responses\\AgentResponse'),
        );

        $this->assertNull(LlmOutputTaintHandler::afterExpressionAnalysis($event));
    }

    #[Test]
    public function it_does_not_source_array_access_reads(): void
    {
        // Psalm discards the taint edge when it resolves `$response['field']`
        // (https://github.com/vimeo/psalm/issues/11912), so an ArrayDimFetch
        // branch here would work around a core gap on one of the hottest node
        // types. Pinned so the deferral is a decision rather than an oversight;
        // the flows it leaves uncovered are in the *KnownLimitation.phpt fixtures.
        $codebase = $this->createCodebase(taintFlowGraph: new TaintFlowGraph());
        $event = $this->createEvent(
            expr: new ArrayDimFetch(new Variable('response'), new String_('summary')),
            codebase: $codebase,
            varType: $this->namedObjectType('Laravel\\Ai\\Responses\\StructuredAgentResponse'),
        );

        $this->assertNull(LlmOutputTaintHandler::afterExpressionAnalysis($event));
    }

    #[Test]
    public function it_returns_null_for_dynamic_property_names(): void
    {
        $codebase = $this->createCodebase(taintFlowGraph: new TaintFlowGraph());
        $event = $this->createEvent(
            expr: new PropertyFetch(new Variable('response'), new Variable('name')),
            codebase: $codebase,
            varType: $this->namedObjectType('Laravel\\Ai\\Responses\\AgentResponse'),
        );

        $this->assertNull(LlmOutputTaintHandler::afterExpressionAnalysis($event));
    }

    #[Test]
    public function it_returns_null_when_the_receiver_type_is_unknown_or_not_an_object(): void
    {
        $codebase = $this->createCodebase(taintFlowGraph: new TaintFlowGraph());

        foreach ([null, Type::getString()] as $varType) {
            $event = $this->createEvent(
                expr: $this->propertyFetch('text'),
                codebase: $codebase,
                varType: $varType,
            );

            $this->assertNull(LlmOutputTaintHandler::afterExpressionAnalysis($event));
        }
    }

    private function propertyFetch(string $propertyName): PropertyFetch
    {
        return new PropertyFetch(new Variable('response'), new Identifier($propertyName));
    }

    private function namedObjectType(string $fqcn): Union
    {
        return new Union([new Type\Atomic\TNamedObject($fqcn)]);
    }

    private function createCodebase(?TaintFlowGraph $taintFlowGraph): Codebase
    {
        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
        $codebase->taint_flow_graph = $taintFlowGraph;

        return $codebase;
    }

    private function createEvent(
        \PhpParser\Node\Expr $expr,
        Codebase $codebase,
        ?Union $varType,
    ): AfterExpressionAnalysisEvent {
        $nodeTypeProvider = $this->createStub(NodeTypeProvider::class);
        $nodeTypeProvider->method('getType')->willReturn($varType);

        $source = $this->createStub(StatementsSource::class);
        $source->method('getNodeTypeProvider')->willReturn($nodeTypeProvider);
        $source->method('getFileName')->willReturn('/dev/null');
        $source->method('getFilePath')->willReturn('/dev/null');

        return new AfterExpressionAnalysisEvent(
            expr: $expr,
            context: new Context(),
            statements_source: $source,
            codebase: $codebase,
        );
    }
}
