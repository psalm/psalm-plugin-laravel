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
        $taintedClasses = $this->taintedProperties()['text'] ?? [];

        $this->assertContains('Laravel\\Ai\\Responses\\TextResponse', $taintedClasses);
        $this->assertContains('Laravel\\Ai\\Responses\\AgentResponse', $taintedClasses);
        // StreamedAgentResponse extends AgentResponse but is named explicitly to
        // short-circuit the `classExtendsOrImplements()` walk on a common type.
        $this->assertContains('Laravel\\Ai\\Responses\\StreamedAgentResponse', $taintedClasses);
        // StreamableAgentResponse is a separate hierarchy that exposes `$text`
        // only after the stream completes — has to be listed explicitly because
        // it does not extend TextResponse upstream.
        $this->assertContains('Laravel\\Ai\\Responses\\StreamableAgentResponse', $taintedClasses);
        // TranscriptionResponse is a third hierarchy: a transcript of user-supplied
        // audio is attacker-authored text that a speech model re-typed.
        $this->assertContains('Laravel\\Ai\\Responses\\TranscriptionResponse', $taintedClasses);
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
    public function it_scopes_the_structured_payload_to_the_structured_responses(): void
    {
        // Not a cross-product with the $text class list: only these two declare
        // $structured, and tainting the property on a class that does not have it
        // would source whatever a user subclass happens to name the same way.
        $this->assertSame([
            'Laravel\\Ai\\Responses\\StructuredAgentResponse',
            'Laravel\\Ai\\Responses\\StructuredTextResponse',
        ], $this->taintedProperties()['structured'] ?? []);
    }

    #[Test]
    public function it_only_taints_the_known_payload_properties(): void
    {
        $this->assertSame(['text', 'structured'], array_keys($this->taintedProperties()));
    }

    /** @return array<string, list<string>> */
    private function taintedProperties(): array
    {
        $reflection = new \ReflectionClass(LlmOutputTaintHandler::class);

        return $reflection->getReflectionConstant('TAINTED_PROPERTIES')?->getValue() ?? [];
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
