<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Views;

use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Codebase\TaintFlowGraph;
use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\LaravelPlugin\Blade\BladeSafetyMap;
use Psalm\LaravelPlugin\Blade\BladeTemplateAnalysis;
use Psalm\LaravelPlugin\Blade\BladeUncertaintyReason;
use Psalm\LaravelPlugin\Blade\BladeViewSafety;
use Psalm\LaravelPlugin\Handlers\Views\BladeAwareViewTaintHandler;
use Psalm\NodeTypeProvider;
use Psalm\Plugin\EventHandler\Event\AfterFunctionCallAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\TaintKind;
use Psalm\Type\Union;

/**
 * Unit coverage for the handler's dispatch logic.
 *
 * Each test builds a real {@see TaintFlowGraph}, hands it to the handler via a
 * stubbed {@see Codebase}, and asserts on the graph's contents through reflection.
 * No Psalm process is spawned: this layer pins behaviour at a granularity that
 * the PHPT/Application layers (which run the real plugin against a real
 * codebase) cannot.
 */
#[CoversClass(BladeAwareViewTaintHandler::class)]
final class BladeAwareViewTaintHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        BladeAwareViewTaintHandler::reset();
    }

    protected function tearDown(): void
    {
        BladeAwareViewTaintHandler::reset();
    }

    #[Test]
    public function does_nothing_when_disabled(): void
    {
        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeFunctionEvent('view', [
            new Arg(new String_('post')),
            new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
        ], $codebase);

        BladeAwareViewTaintHandler::afterFunctionCallAnalysis($event);

        $this->assertSame([], $this->graphSinks($graph));
    }

    #[Test]
    public function does_nothing_when_taint_graph_absent(): void
    {
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $codebase = $this->createCodebase(null);

        $event = $this->makeFunctionEvent('view', [
            new Arg(new String_('post')),
            new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
        ], $codebase);

        BladeAwareViewTaintHandler::afterFunctionCallAnalysis($event);

        // No graph was passed in, so nothing observable to assert beyond not
        // throwing. The real signal is that the handler exited early —
        // dereferencing $codebase->taint_flow_graph would have crashed.
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function ignores_unrelated_function_id(): void
    {
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeFunctionEvent('unrelated', [
            new Arg(new String_('post')),
            new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
        ], $codebase);

        BladeAwareViewTaintHandler::afterFunctionCallAnalysis($event);

        $this->assertSame([], $this->graphSinks($graph));
    }

    #[Test]
    public function installs_per_key_sinks_for_unsafe_keys_view(): void
    {
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeFunctionEvent('view', [
            new Arg(new String_('post')),
            new Arg($this->arrayLiteral([
                'bio' => $this->taintedVariable('bio'),
                // 'title' is not on the unsafe-keys list; it must not receive a sink.
                'title' => $this->taintedVariable('title'),
            ])),
        ], $codebase);

        BladeAwareViewTaintHandler::afterFunctionCallAnalysis($event);

        $sinks = $this->graphSinks($graph);

        $this->assertCount(1, $sinks, 'Exactly one sink should be installed: the unsafe key.');
        $this->assertStringContainsString("(post, 'bio')", $sinks[0]['label']);
        $this->assertSame(TaintKind::INPUT_HTML, $sinks[0]['taints']);
    }

    #[Test]
    public function safe_view_installs_no_sink(): void
    {
        $this->initWithMap([
            'home' => new BladeViewSafety('home', '/views/home.blade.php', BladeTemplateAnalysis::safe()),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeFunctionEvent('view', [
            new Arg(new String_('home')),
            new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
        ], $codebase);

        BladeAwareViewTaintHandler::afterFunctionCallAnalysis($event);

        $this->assertSame([], $this->graphSinks($graph));
    }

    #[Test]
    public function unknown_view_installs_sink_on_every_data_key(): void
    {
        $this->initWithMap([
            'broken' => new BladeViewSafety(
                'broken',
                '/views/broken.blade.php',
                BladeTemplateAnalysis::unknown([BladeUncertaintyReason::LayoutSectionFlow]),
            ),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeFunctionEvent('view', [
            new Arg(new String_('broken')),
            new Arg($this->arrayLiteral([
                'bio' => $this->taintedVariable('bio'),
                'title' => $this->taintedVariable('title'),
            ])),
        ], $codebase);

        BladeAwareViewTaintHandler::afterFunctionCallAnalysis($event);

        $sinks = $this->graphSinks($graph);

        $this->assertCount(2, $sinks, 'UNKNOWN must sink every entry of the data array.');
        $this->assertSinkLabelExists($sinks, "(broken, 'bio')");
        $this->assertSinkLabelExists($sinks, "(broken, 'title')");
    }

    #[Test]
    public function dynamic_view_name_installs_whole_data_sink(): void
    {
        $this->initWithMap([]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeFunctionEvent('view', [
            new Arg(new Variable('dynamicViewName')),
            new Arg($this->arrayLiteral([
                'bio' => $this->taintedVariable('bio'),
                'title' => $this->taintedVariable('title'),
            ])),
        ], $codebase);

        BladeAwareViewTaintHandler::afterFunctionCallAnalysis($event);

        $sinks = $this->graphSinks($graph);

        $this->assertCount(2, $sinks);
        $this->assertSinkLabelExists($sinks, "(<dynamic>, 'bio')");
        $this->assertSinkLabelExists($sinks, "(<dynamic>, 'title')");
    }

    #[Test]
    public function missing_view_installs_no_sink(): void
    {
        // Empty map: every literal view name yields safetyFor() === null. The
        // missing-view diagnostic is MissingViewHandler's job; this handler must
        // not double-report.
        $this->initWithMap([]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeFunctionEvent('view', [
            new Arg(new String_('does-not-exist')),
            new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
        ], $codebase);

        BladeAwareViewTaintHandler::afterFunctionCallAnalysis($event);

        $this->assertSame([], $this->graphSinks($graph));
    }

    #[Test]
    public function method_call_on_factory_make_installs_sink(): void
    {
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeMethodEvent(
            \Illuminate\View\Factory::class . '::make',
            [
                new Arg(new String_('post')),
                new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $sinks = $this->graphSinks($graph);

        $this->assertCount(1, $sinks);
        $this->assertStringContainsString("(post, 'bio')", $sinks[0]['label']);
    }

    #[Test]
    public function method_call_on_unregistered_method_is_ignored(): void
    {
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeMethodEvent(
            \Illuminate\View\Factory::class . '::renderEach',
            [
                new Arg(new String_('post')),
                new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSame([], $this->graphSinks($graph));
    }

    #[Test]
    public function merge_data_is_sunk_alongside_data(): void
    {
        $this->initWithMap([
            'post' => new BladeViewSafety(
                'post',
                '/views/post.blade.php',
                BladeTemplateAnalysis::unsafeKeys(['bio', 'note']),
            ),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeFunctionEvent('view', [
            new Arg(new String_('post')),
            new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
            new Arg($this->arrayLiteral(['note' => $this->taintedVariable('note')])),
        ], $codebase);

        BladeAwareViewTaintHandler::afterFunctionCallAnalysis($event);

        $sinks = $this->graphSinks($graph);

        $this->assertSinkLabelExists($sinks, "(post, 'bio')");
        $this->assertSinkLabelExists($sinks, "(post, 'note')");
    }

    #[Test]
    public function non_array_literal_data_falls_back_to_whole_argument_sink(): void
    {
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeFunctionEvent('view', [
            new Arg(new String_('post')),
            new Arg($this->taintedVariable('data')),
        ], $codebase);

        BladeAwareViewTaintHandler::afterFunctionCallAnalysis($event);

        $this->assertSinkLabelExists($this->graphSinks($graph), "(post, '<argument>')");
    }

    #[Test]
    public function path_added_taints_stay_zero_to_avoid_cross_kind_false_positives(): void
    {
        // Regression guard for the addPath argument fix. The edge that joins a
        // value's parent_node to our html sink must NOT lift unrelated taint
        // kinds (INPUT_SQL, INPUT_SHELL, etc.) into INPUT_HTML mid-flow. The
        // sink's `$taints` field is the kind gate; the path's
        // `$added_taints` is the call-site delta and must remain 0.
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeFunctionEvent('view', [
            new Arg(new String_('post')),
            new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
        ], $codebase);

        BladeAwareViewTaintHandler::afterFunctionCallAnalysis($event);

        $this->assertCount(1, $this->graphSinks($graph));

        $forwardEdges = (new \ReflectionProperty(TaintFlowGraph::class, 'forward_edges'))->getValue($graph);
        \assert(\is_array($forwardEdges));

        $observedAddedTaints = [];

        foreach ($forwardEdges as $perFrom) {
            \assert(\is_array($perFrom));

            foreach ($perFrom as $path) {
                $this->assertInstanceOf(\Psalm\Internal\DataFlow\Path::class, $path);
                $observedAddedTaints[] = $path->added_taints;
            }
        }

        $this->assertNotEmpty($observedAddedTaints, 'Expected at least one path on the taint graph.');
        $this->assertSame([0], \array_values(\array_unique($observedAddedTaints)));
    }

    #[Test]
    public function method_call_on_contracts_factory_make_installs_sink(): void
    {
        // Apps that DI \Illuminate\Contracts\View\Factory (the interface) instead
        // of the concrete class produce method ids resolved to the contract.
        // The dispatcher must cover this surface, otherwise PSR-typed code
        // bypasses the handler silently.
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeMethodEvent(
            \Illuminate\Contracts\View\Factory::class . '::make',
            [
                new Arg(new String_('post')),
                new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertCount(1, $this->graphSinks($graph));
    }

    #[Test]
    public function method_call_on_factory_render_when_installs_sink(): void
    {
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        // renderWhen($condition, $view, $data = [], $mergeData = [])
        // viewArgIndex = 1, dataArgIndex = 2
        $event = $this->makeMethodEvent(
            // Lowercase suffix mirrors what Psalm's MethodIdentifier produces
            // (method-name part is `lowercase-string`).
            \Illuminate\View\Factory::class . '::renderwhen',
            [
                new Arg(new Variable('condition')),
                new Arg(new String_('post')),
                new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSinkLabelExists($this->graphSinks($graph), "(post, 'bio')");
    }

    #[Test]
    public function method_call_on_factory_render_unless_installs_sink(): void
    {
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeMethodEvent(
            \Illuminate\View\Factory::class . '::renderunless',
            [
                new Arg(new Variable('condition')),
                new Arg(new String_('post')),
                new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSinkLabelExists($this->graphSinks($graph), "(post, 'bio')");
    }

    #[Test]
    public function inner_array_spread_falls_back_to_whole_argument_sink(): void
    {
        // `view('post', ['safe' => $a, ...$rest])` cannot enumerate $rest's
        // keys at analysis time. The handler must treat this as the whole-arg
        // sink so any unsafe-key entries hiding in $rest still surface.
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $spreadItem = new ArrayItem($this->taintedVariable('rest'));
        $spreadItem->unpack = true;

        $arrayWithSpread = new Array_([
            new ArrayItem($this->taintedVariable('safe'), new String_('safe')),
            $spreadItem,
        ]);

        $event = $this->makeFunctionEvent('view', [
            new Arg(new String_('post')),
            new Arg($arrayWithSpread),
        ], $codebase);

        BladeAwareViewTaintHandler::afterFunctionCallAnalysis($event);

        $this->assertSinkLabelExists($this->graphSinks($graph), "(post, '<argument>')");
    }

    #[Test]
    public function dynamic_array_key_falls_back_to_whole_argument_sink(): void
    {
        // `view('post', [$maybeKey => $val])`: a runtime-resolved key could
        // collide with an unsafe key in UNSAFE_KEYS mode. Symmetric to the
        // inner-spread case — drop into the whole-arg fallback rather than
        // silently skip the entry.
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $dynamicKeyed = new Array_([
            new ArrayItem(
                $this->taintedVariable('value'),
                $this->taintedVariable('maybeKey'),
            ),
        ]);

        $event = $this->makeFunctionEvent('view', [
            new Arg(new String_('post')),
            new Arg($dynamicKeyed),
        ], $codebase);

        BladeAwareViewTaintHandler::afterFunctionCallAnalysis($event);

        $this->assertSinkLabelExists($this->graphSinks($graph), "(post, '<argument>')");
    }

    #[Test]
    public function non_identifier_keys_get_no_sink(): void
    {
        // Reject branches in literalArrayKey(): integer keys, list-style
        // entries, and non-identifier strings (`extract()` skips all three).
        // Only the valid identifier 'bio' should receive a sink even though
        // 'bio' is also (under different shapes) in the array literal.
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $items = [
            new ArrayItem($this->taintedVariable('bio'), new String_('bio')),
            // Integer key — handler must skip.
            new ArrayItem($this->taintedVariable('intKeyed')),
            // Hyphenated string — not a PHP identifier, extract() skips.
            new ArrayItem($this->taintedVariable('hyphen'), new String_('invalid-key')),
            // Empty string — extract() skips.
            new ArrayItem($this->taintedVariable('empty'), new String_('')),
        ];

        $event = $this->makeFunctionEvent('view', [
            new Arg(new String_('post')),
            new Arg(new Array_($items)),
        ], $codebase);

        BladeAwareViewTaintHandler::afterFunctionCallAnalysis($event);

        $sinks = $this->graphSinks($graph);
        $this->assertCount(1, $sinks);
        $this->assertSinkLabelExists($sinks, "(post, 'bio')");
    }

    #[Test]
    public function multiple_unsafe_keys_each_get_their_own_sink(): void
    {
        // Lock in the per-key loop: a single call with two unsafe keys + one
        // safe key must yield exactly two sinks with the right labels.
        $this->initWithMap([
            'post' => new BladeViewSafety(
                'post',
                '/views/post.blade.php',
                BladeTemplateAnalysis::unsafeKeys(['bio', 'note']),
            ),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeFunctionEvent('view', [
            new Arg(new String_('post')),
            new Arg($this->arrayLiteral([
                'bio' => $this->taintedVariable('bio'),
                'note' => $this->taintedVariable('note'),
                'title' => $this->taintedVariable('title'),
            ])),
        ], $codebase);

        BladeAwareViewTaintHandler::afterFunctionCallAnalysis($event);

        $sinks = $this->graphSinks($graph);
        $this->assertCount(2, $sinks);
        $this->assertSinkLabelExists($sinks, "(post, 'bio')");
        $this->assertSinkLabelExists($sinks, "(post, 'note')");
    }

    #[Test]
    public function empty_parent_nodes_short_circuit_skips_sink(): void
    {
        // installSinkForExpression() bails when the value Union has no
        // parent_nodes — there is no data flow to plumb, so allocating a
        // sink would just bloat the graph during whole-program analysis.
        // The test supplies a Variable that the type provider returns null
        // for (no Union → no parent_nodes).
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $untypedValue = new Variable('untyped');

        // Bypass our makeSource helper's typing pass: build the source manually
        // so $untypedValue does NOT get parent_nodes registered.
        $source = $this->createStub(StatementsSource::class);
        $source->method('getFilePath')->willReturn('/app/Untyped.php');
        $source->method('getFileName')->willReturn('Untyped.php');
        $source->method('getNodeTypeProvider')->willReturn(
            new class implements NodeTypeProvider {
                #[\Override]
                public function getType(\PhpParser\NodeAbstract $node): ?Union
                {
                    return null;
                }

                #[\Override]
                public function setType(\PhpParser\NodeAbstract $node, Union $type): void {}
            },
        );

        $funcCall = new FuncCall(new Name('view'), [
            new Arg(new String_('post')),
            new Arg($this->arrayLiteral(['bio' => $untypedValue])),
        ]);
        $funcCall->setAttribute('startFilePos', 0);
        $funcCall->setAttribute('endFilePos', 100);

        $event = new AfterFunctionCallAnalysisEvent(
            $funcCall,
            'view',
            new Context(),
            $source,
            $codebase,
            Type::getMixed(),
            [],
        );

        BladeAwareViewTaintHandler::afterFunctionCallAnalysis($event);

        $this->assertSame([], $this->graphSinks($graph));
    }

    #[Test]
    public function merge_data_unknown_view_installs_whole_data_sinks_on_both(): void
    {
        $this->initWithMap([
            'broken' => new BladeViewSafety(
                'broken',
                '/views/broken.blade.php',
                BladeTemplateAnalysis::unknown([BladeUncertaintyReason::LayoutSectionFlow]),
            ),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeFunctionEvent('view', [
            new Arg(new String_('broken')),
            new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
            new Arg($this->arrayLiteral(['note' => $this->taintedVariable('note')])),
        ], $codebase);

        BladeAwareViewTaintHandler::afterFunctionCallAnalysis($event);

        $sinks = $this->graphSinks($graph);
        $this->assertSinkLabelExists($sinks, "(broken, 'bio')");
        $this->assertSinkLabelExists($sinks, "(broken, 'note')");
    }

    #[Test]
    public function merge_data_dynamic_view_installs_whole_data_sinks_on_both(): void
    {
        $this->initWithMap([]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeFunctionEvent('view', [
            new Arg(new Variable('dynamic')),
            new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
            new Arg($this->arrayLiteral(['note' => $this->taintedVariable('note')])),
        ], $codebase);

        BladeAwareViewTaintHandler::afterFunctionCallAnalysis($event);

        $sinks = $this->graphSinks($graph);
        $this->assertSinkLabelExists($sinks, "(<dynamic>, 'bio')");
        $this->assertSinkLabelExists($sinks, "(<dynamic>, 'note')");
    }

    #[Test]
    public function function_id_case_insensitive_match(): void
    {
        // Psalm preserves call-site casing in `function_id` (only the leading
        // backslash is stripped). The handler must compare case-insensitively
        // so `\View(...)` / unusual capitalisations are not silently bypassed.
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeFunctionEvent('View', [
            new Arg(new String_('post')),
            new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
        ], $codebase);

        BladeAwareViewTaintHandler::afterFunctionCallAnalysis($event);

        $this->assertSinkLabelExists($this->graphSinks($graph), "(post, 'bio')");
    }

    /**
     * Build the handler with a synthetic safety map. The map is opaque to the
     * scanner here: tests fabricate {@see BladeViewSafety} records directly,
     * bypassing the on-disk scan.
     *
     * @param array<string, BladeViewSafety> $safetyByView
     */
    private function initWithMap(array $safetyByView): void
    {
        BladeAwareViewTaintHandler::init(new BladeSafetyMap($safetyByView));
    }

    /**
     * Build an {@see AfterFunctionCallAnalysisEvent} wired to a fake source
     * whose {@see NodeTypeProvider} returns a typed-with-parent-nodes Union for
     * every variable expression. The parent-node id matches the variable name
     * so assertions can identify which value ended up on the sink path.
     *
     * @param list<Arg> $args
     */
    private function makeFunctionEvent(string $functionId, array $args, Codebase $codebase): AfterFunctionCallAnalysisEvent
    {
        $funcCall = new FuncCall(new Name($functionId), $args);
        $funcCall->setAttribute('startFilePos', 0);
        $funcCall->setAttribute('endFilePos', 100);

        $source = $this->makeSource($args);

        return new AfterFunctionCallAnalysisEvent(
            $funcCall,
            $functionId === '' ? 'view' : $functionId,
            new Context(),
            $source,
            $codebase,
            Type::getMixed(),
            [],
        );
    }

    /**
     * @param list<Arg> $args
     */
    private function makeMethodEvent(string $methodId, array $args, Codebase $codebase): AfterMethodCallAnalysisEvent
    {
        $staticCall = new StaticCall(
            new Name(\explode('::', $methodId)[0]),
            \explode('::', $methodId)[1],
            $args,
        );
        $staticCall->setAttribute('startFilePos', 0);
        $staticCall->setAttribute('endFilePos', 100);

        $source = $this->makeSource($args);

        return new AfterMethodCallAnalysisEvent(
            $staticCall,
            $methodId,
            $methodId,
            $methodId,
            new Context(),
            $source,
            $codebase,
        );
    }

    /**
     * Build a {@see StatementsSource} whose {@see NodeTypeProvider::getType}
     * returns a Union with a single parent_node for every {@see Variable}
     * expression in $args. ArrayItems' values are recursively typed too so
     * the per-key path picks them up.
     *
     * Only Variable nodes get typed; literal strings stay null-typed because
     * the handler does not query their types (it reads .value directly).
     *
     * @param list<Arg> $args
     */
    private function makeSource(array $args): StatementsSource
    {
        /** @var \SplObjectStorage<\PhpParser\NodeAbstract, Union> $typeMap */
        $typeMap = new \SplObjectStorage();

        foreach ($args as $arg) {
            $this->collectTypedExpressions($arg->value, $typeMap);
        }

        $source = $this->createStub(StatementsSource::class);
        $source->method('getFilePath')->willReturn('/app/HomeController.php');
        $source->method('getFileName')->willReturn('HomeController.php');

        $typeProvider = new class ($typeMap) implements NodeTypeProvider {
            /** @param \SplObjectStorage<\PhpParser\NodeAbstract, Union> $typeMap */
            public function __construct(private readonly \SplObjectStorage $typeMap) {}

            #[\Override]
            public function getType(\PhpParser\NodeAbstract $node): ?Union
            {
                /** @var Union|null $type */
                $type = $this->typeMap[$node] ?? null;

                return $type;
            }

            #[\Override]
            public function setType(\PhpParser\NodeAbstract $node, Union $type): void
            {
                $this->typeMap[$node] = $type;
            }
        };

        $source->method('getNodeTypeProvider')->willReturn($typeProvider);

        return $source;
    }

    /**
     * Walk an expression and record a typed Union (with a parent_node tagged by
     * the variable name) for every Variable encountered. Only Variable
     * expressions need typing — they are the only nodes the handler walks
     * past array literals to query.
     *
     * @param \SplObjectStorage<\PhpParser\NodeAbstract, Union> $typeMap
     */
    private function collectTypedExpressions(\PhpParser\Node $node, \SplObjectStorage $typeMap): void
    {
        if ($node instanceof Variable && \is_string($node->name)) {
            $parentNode = DataFlowNode::make(
                'var:' . $node->name,
                $node->name,
                null,
                null,
                TaintKind::INPUT_HTML,
            );

            $type = Type::getString();
            $typeMap[$node] = $type->setParentNodes([$parentNode->id => $parentNode]);

            return;
        }

        if ($node instanceof Array_) {
            // Type the Array_ itself with a parent_node too — the whole-arg
            // sink path in installWholeDataSink fetches getType($arg->value)
            // when the array literal cannot be walked per-item (e.g. inner
            // spread). Real Psalm assigns parent_nodes to array literals via
            // getForAssignment(); we mirror that with a synthetic node.
            $arrayParent = DataFlowNode::make(
                'array:' . \spl_object_id($node),
                'inline-array',
                null,
                null,
                TaintKind::INPUT_HTML,
            );

            $arrayType = Type::getArray();
            $typeMap[$node] = $arrayType->setParentNodes([$arrayParent->id => $arrayParent]);

            foreach ($node->items as $item) {
                if ($item instanceof ArrayItem) {
                    $this->collectTypedExpressions($item->value, $typeMap);
                }
            }

            return;
        }
    }

    private function arrayLiteral(array $entries): Array_
    {
        $items = [];

        foreach ($entries as $key => $value) {
            $items[] = new ArrayItem($value, new String_((string) $key));
        }

        return new Array_($items);
    }

    private function taintedVariable(string $name): Variable
    {
        return new Variable($name);
    }

    /**
     * Build a Codebase whose only relevant field — `$taint_flow_graph` — is
     * the test-supplied graph. The real Codebase wires up dozens of providers
     * that are irrelevant here; reflection lets us short-circuit construction.
     */
    private function createCodebase(?TaintFlowGraph $graph): Codebase
    {
        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();

        $property = new \ReflectionProperty(Codebase::class, 'taint_flow_graph');
        $property->setValue($codebase, $graph);

        return $codebase;
    }

    /**
     * Custom assertion: at least one sink label contains the given fragment.
     *
     * Labels carry an argument-offset suffix (`#1`) appended by
     * {@see DataFlowNode::getForMethodArgument}, so the test cannot match the
     * full string verbatim; substring matching keeps the assertion robust
     * against that suffix changing position in future Psalm versions.
     *
     * @param list<array{id: string, label: string, taints: int}> $sinks
     */
    private function assertSinkLabelExists(array $sinks, string $fragment): void
    {
        $labels = \array_column($sinks, 'label');

        foreach ($labels as $label) {
            if (\str_contains($label, $fragment)) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail(\sprintf(
            "No sink label contained '%s'. Observed labels: [%s]",
            $fragment,
            \implode(', ', $labels),
        ));
    }

    /**
     * Snapshot a {@see TaintFlowGraph}'s sinks via reflection. The class keeps
     * its node tables private, but they are stable across Psalm 7.x patch
     * versions (the property names ship in `composer.lock`).
     *
     * @return list<array{id: string, label: string, taints: int}>
     */
    private function graphSinks(TaintFlowGraph $graph): array
    {
        $sinks = (new \ReflectionProperty(TaintFlowGraph::class, 'sinks'))->getValue($graph);

        \assert(\is_array($sinks));

        $out = [];

        foreach ($sinks as $sink) {
            $this->assertInstanceOf(DataFlowNode::class, $sink);
            $out[] = [
                'id' => $sink->id,
                'label' => $sink->label,
                'taints' => $sink->taints,
            ];
        }

        return $out;
    }
}
