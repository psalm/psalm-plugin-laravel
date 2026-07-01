<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Ai;

use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
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
 * Unit-level coverage for the early-exit gates and class matching in
 * {@see LlmOutputTaintHandler}. End-to-end taint propagation is covered by
 * the PHPT suite under `tests/Type/tests/PromptInjection/`, which needs a
 * real Psalm analyzer; these tests intentionally exercise only the cheap
 * branches that decide whether to call `Codebase::addTaintSource()` at all.
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
    public function it_returns_null_when_property_name_is_not_text(): void
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
    public function it_lists_all_known_response_classes(): void
    {
        $reflection = new \ReflectionClass(LlmOutputTaintHandler::class);
        /** @var list<string> $taintedClasses */
        $taintedClasses = $reflection->getReflectionConstant('TAINTED_CLASSES')?->getValue() ?? [];

        $this->assertContains('Laravel\\Ai\\Responses\\TextResponse', $taintedClasses);
        $this->assertContains('Laravel\\Ai\\Responses\\AgentResponse', $taintedClasses);
        // StreamedAgentResponse extends AgentResponse but is named explicitly to
        // short-circuit the `classExtendsOrImplements()` walk on a common type.
        $this->assertContains('Laravel\\Ai\\Responses\\StreamedAgentResponse', $taintedClasses);
        // StreamableAgentResponse is a separate hierarchy that exposes `$text`
        // only after the stream completes — has to be listed explicitly because
        // it does not extend TextResponse upstream.
        $this->assertContains('Laravel\\Ai\\Responses\\StreamableAgentResponse', $taintedClasses);
    }

    #[Test]
    public function it_only_taints_the_text_property(): void
    {
        $reflection = new \ReflectionClass(LlmOutputTaintHandler::class);
        /** @var list<string> $taintedProperties */
        $taintedProperties = $reflection->getReflectionConstant('TAINTED_PROPERTIES')?->getValue() ?? [];

        $this->assertSame(['text'], $taintedProperties);
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
