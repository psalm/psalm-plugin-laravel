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

        // `share` is a real Factory method but is intentionally NOT in the
        // dispatch table (see buildMethodSpecs' deferred-list comment). The
        // hot-path suffix gate should reject it cleanly.
        $event = $this->makeMethodEvent(
            \Illuminate\View\Factory::class . '::share',
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

    #[Test]
    public function factory_first_unions_unsafe_keys_across_literal_templates(): void
    {
        // first(['a', 'b'], $data): scanner sees 'a' has 'bio', 'b' has 'title'.
        // The handler sinks both keys when present in the data array.
        $this->initWithMap([
            'a' => new BladeViewSafety('a', '/views/a.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
            'b' => new BladeViewSafety('b', '/views/b.blade.php', BladeTemplateAnalysis::unsafeKeys(['title'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeMethodEvent(\Illuminate\View\Factory::class . '::first', [
            new Arg($this->viewNameList(['a', 'b'])),
            new Arg($this->arrayLiteral([
                'bio' => $this->taintedVariable('bio'),
                'title' => $this->taintedVariable('title'),
                'safe' => $this->taintedVariable('safe'),
            ])),
        ], $codebase);

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $sinks = $this->graphSinks($graph);

        $this->assertCount(2, $sinks, 'Union of bio + title; safe is not sunk.');
        $this->assertSinkLabelExists($sinks, "'bio'");
        $this->assertSinkLabelExists($sinks, "'title'");
    }

    #[Test]
    public function factory_first_with_unknown_template_falls_back_to_whole_data_sink(): void
    {
        // first(['a', 'b']) where 'a' is UNKNOWN — the unsafe-key union loses
        // soundness, so the call falls back to the whole-data sink.
        $this->initWithMap([
            'a' => new BladeViewSafety(
                'a',
                '/views/a.blade.php',
                BladeTemplateAnalysis::unknown([BladeUncertaintyReason::LayoutSectionFlow]),
            ),
            'b' => new BladeViewSafety('b', '/views/b.blade.php', BladeTemplateAnalysis::unsafeKeys(['title'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeMethodEvent(\Illuminate\View\Factory::class . '::first', [
            new Arg($this->viewNameList(['a', 'b'])),
            new Arg($this->arrayLiteral([
                'bio' => $this->taintedVariable('bio'),
                'title' => $this->taintedVariable('title'),
            ])),
        ], $codebase);

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $sinks = $this->graphSinks($graph);

        // Whole-data fallback sinks BOTH keys regardless of which template
        // tripped the fallback.
        $this->assertCount(2, $sinks);
    }

    #[Test]
    public function factory_first_with_non_literal_array_item_falls_back_to_whole_data(): void
    {
        $this->initWithMap([
            'a' => new BladeViewSafety('a', '/views/a.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        // One literal, one variable item. Whole-data sink fires because the
        // second template's name is opaque at analysis time.
        $viewsArray = new Array_([
            new ArrayItem(new String_('a')),
            new ArrayItem($this->taintedVariable('dynamicTemplate')),
        ]);

        $event = $this->makeMethodEvent(\Illuminate\View\Factory::class . '::first', [
            new Arg($viewsArray),
            new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
        ], $codebase);

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $sinks = $this->graphSinks($graph);

        // Whole-data fallback fires. The label carries the first literal
        // candidate name encountered before the non-literal item (here 'a').
        // When no literal candidate is resolved first, the dispatcher would
        // emit '<first-dynamic>' instead.
        $this->assertSinkLabelExists($sinks, "(a, 'bio')");
    }

    #[Test]
    public function factory_first_with_non_array_views_arg_falls_back_to_dynamic(): void
    {
        $this->initWithMap([]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeMethodEvent(\Illuminate\View\Factory::class . '::first', [
            new Arg($this->taintedVariable('views')),
            new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
        ], $codebase);

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSinkLabelExists($this->graphSinks($graph), "(<dynamic>, 'bio')");
    }

    #[Test]
    public function factory_first_all_safe_templates_install_no_sink(): void
    {
        $this->initWithMap([
            'a' => new BladeViewSafety('a', '/views/a.blade.php', BladeTemplateAnalysis::safe()),
            'b' => new BladeViewSafety('b', '/views/b.blade.php', BladeTemplateAnalysis::safe()),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeMethodEvent(\Illuminate\View\Factory::class . '::first', [
            new Arg($this->viewNameList(['a', 'b'])),
            new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
        ], $codebase);

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSame([], $this->graphSinks($graph));
    }

    #[Test]
    public function factory_render_each_with_literal_iterator_matching_unsafe_key(): void
    {
        // renderEach('row', $items, 'user'): the child template binds each
        // $items element under $user. If 'user' is an unsafe key, sink $items.
        $this->initWithMap([
            'row' => new BladeViewSafety('row', '/views/row.blade.php', BladeTemplateAnalysis::unsafeKeys(['user'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeMethodEvent(\Illuminate\View\Factory::class . '::rendereach', [
            new Arg(new String_('row')),
            new Arg($this->taintedVariable('items')),
            new Arg(new String_('user')),
        ], $codebase);

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSinkLabelExists($this->graphSinks($graph), "(row, 'user')");
    }

    #[Test]
    public function factory_render_each_with_non_matching_iterator_installs_no_sink(): void
    {
        $this->initWithMap([
            'row' => new BladeViewSafety('row', '/views/row.blade.php', BladeTemplateAnalysis::unsafeKeys(['other'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        // 'user' iterator does not match the unsafe key 'other'.
        $event = $this->makeMethodEvent(\Illuminate\View\Factory::class . '::rendereach', [
            new Arg(new String_('row')),
            new Arg($this->taintedVariable('items')),
            new Arg(new String_('user')),
        ], $codebase);

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSame([], $this->graphSinks($graph));
    }

    #[Test]
    public function factory_render_each_with_non_literal_iterator_falls_back_to_whole_data(): void
    {
        $this->initWithMap([
            'row' => new BladeViewSafety('row', '/views/row.blade.php', BladeTemplateAnalysis::unsafeKeys(['user'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeMethodEvent(\Illuminate\View\Factory::class . '::rendereach', [
            new Arg(new String_('row')),
            new Arg($this->taintedVariable('items')),
            new Arg($this->taintedVariable('iteratorName')),
        ], $codebase);

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSinkLabelExists($this->graphSinks($graph), "(row, '<argument>')");
    }

    #[Test]
    public function factory_render_each_with_unknown_template_falls_back_to_whole_data(): void
    {
        $this->initWithMap([
            'row' => new BladeViewSafety(
                'row',
                '/views/row.blade.php',
                BladeTemplateAnalysis::unknown([BladeUncertaintyReason::LayoutSectionFlow]),
            ),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeMethodEvent(\Illuminate\View\Factory::class . '::rendereach', [
            new Arg(new String_('row')),
            new Arg($this->taintedVariable('items')),
            new Arg(new String_('user')),
        ], $codebase);

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSinkLabelExists($this->graphSinks($graph), "(row, '<argument>')");
    }

    #[Test]
    public function response_factory_view_installs_per_key_sink(): void
    {
        // Same shape as Factory::make() but on a different class. The contract
        // and concrete class are both registered; both must dispatch.
        $this->initWithMap([
            'profile' => new BladeViewSafety(
                'profile',
                '/views/profile.blade.php',
                BladeTemplateAnalysis::unsafeKeys(['bio']),
            ),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeMethodEvent(
            \Illuminate\Routing\ResponseFactory::class . '::view',
            [
                new Arg(new String_('profile')),
                new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSinkLabelExists($this->graphSinks($graph), "(profile, 'bio')");
    }

    #[Test]
    public function response_factory_contract_view_installs_per_key_sink(): void
    {
        $this->initWithMap([
            'profile' => new BladeViewSafety(
                'profile',
                '/views/profile.blade.php',
                BladeTemplateAnalysis::unsafeKeys(['bio']),
            ),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeMethodEvent(
            \Illuminate\Contracts\Routing\ResponseFactory::class . '::view',
            [
                new Arg(new String_('profile')),
                new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSinkLabelExists($this->graphSinks($graph), "(profile, 'bio')");
    }

    #[Test]
    public function view_with_chained_off_view_helper_installs_single_key_sink(): void
    {
        // view('post')->with('bio', $bio) — receiver resolves to 'post' via
        // FuncCall walk. Single-key sink fires when 'bio' is unsafe.
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $bioVar = $this->taintedVariable('bio');

        // Build the chained receiver expression.
        $viewCall = new FuncCall(new Name('view'), [new Arg(new String_('post'))]);

        $event = $this->makeInstanceMethodEvent(
            \Illuminate\View\View::class . '::with',
            $viewCall,
            [
                new Arg(new String_('bio')),
                new Arg($bioVar),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSinkLabelExists($this->graphSinks($graph), "(post, 'bio')");
    }

    #[Test]
    public function view_with_chained_off_factory_make_installs_single_key_sink(): void
    {
        // \Illuminate\Support\Facades\View::make('post')->with('bio', $bio) —
        // static call receiver.
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $bioVar = $this->taintedVariable('bio');

        $makeCall = new StaticCall(
            new Name(\Illuminate\Support\Facades\View::class),
            'make',
            [new Arg(new String_('post'))],
        );

        $event = $this->makeInstanceMethodEvent(
            \Illuminate\View\View::class . '::with',
            $makeCall,
            [
                new Arg(new String_('bio')),
                new Arg($bioVar),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSinkLabelExists($this->graphSinks($graph), "(post, 'bio')");
    }

    #[Test]
    public function view_with_unresolvable_receiver_installs_no_sink(): void
    {
        // $v->with('bio', $bio) — receiver is a bare variable; we can't trace
        // the view name back through it. No sink, per the documented policy.
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeInstanceMethodEvent(
            \Illuminate\View\View::class . '::with',
            $this->taintedVariable('viewInstance'),
            [
                new Arg(new String_('bio')),
                new Arg($this->taintedVariable('bio')),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSame([], $this->graphSinks($graph));
    }

    #[Test]
    public function view_with_non_matching_key_installs_no_sink(): void
    {
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $viewCall = new FuncCall(new Name('view'), [new Arg(new String_('post'))]);

        $event = $this->makeInstanceMethodEvent(
            \Illuminate\View\View::class . '::with',
            $viewCall,
            [
                new Arg(new String_('title')),
                new Arg($this->taintedVariable('title')),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSame([], $this->graphSinks($graph));
    }

    #[Test]
    public function view_with_unknown_template_falls_back_to_value_sink(): void
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

        $viewCall = new FuncCall(new Name('view'), [new Arg(new String_('broken'))]);

        $event = $this->makeInstanceMethodEvent(
            \Illuminate\View\View::class . '::with',
            $viewCall,
            [
                new Arg(new String_('bio')),
                new Arg($this->taintedVariable('bio')),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        // UNKNOWN template + literal key → sink installed on $value with the
        // literal key name (NOT the whole-arg fallback label).
        $this->assertSinkLabelExists($this->graphSinks($graph), "(broken, 'bio')");
    }

    #[Test]
    public function view_with_chained_through_prior_with_call(): void
    {
        // view('post')->with('first', 1)->with('bio', $bio) — the receiver of
        // the outer with() is a MethodCall whose method-name is 'with'. The
        // resolver recurses into its receiver to recover 'post'.
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $viewCall = new FuncCall(new Name('view'), [new Arg(new String_('post'))]);
        $innerWith = new \PhpParser\Node\Expr\MethodCall(
            $viewCall,
            'with',
            [new Arg(new String_('first')), new Arg(new String_('1'))],
        );

        $event = $this->makeInstanceMethodEvent(
            \Illuminate\View\View::class . '::with',
            $innerWith,
            [
                new Arg(new String_('bio')),
                new Arg($this->taintedVariable('bio')),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSinkLabelExists($this->graphSinks($graph), "(post, 'bio')");
    }

    #[Test]
    public function mailable_view_installs_per_key_sink(): void
    {
        $this->initWithMap([
            'emails.welcome' => new BladeViewSafety(
                'emails.welcome',
                '/views/emails/welcome.blade.php',
                BladeTemplateAnalysis::unsafeKeys(['bio']),
            ),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeMethodEvent(
            \Illuminate\Mail\Mailable::class . '::view',
            [
                new Arg(new String_('emails.welcome')),
                new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSinkLabelExists($this->graphSinks($graph), "(emails.welcome, 'bio')");
    }

    #[Test]
    public function mailable_markdown_installs_per_key_sink(): void
    {
        $this->initWithMap([
            'emails.welcome' => new BladeViewSafety(
                'emails.welcome',
                '/views/emails/welcome.blade.php',
                BladeTemplateAnalysis::unsafeKeys(['bio']),
            ),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeMethodEvent(
            \Illuminate\Mail\Mailable::class . '::markdown',
            [
                new Arg(new String_('emails.welcome')),
                new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSinkLabelExists($this->graphSinks($graph), "(emails.welcome, 'bio')");
    }

    #[Test]
    public function mailable_text_installs_per_key_sink(): void
    {
        $this->initWithMap([
            'emails.welcome-text' => new BladeViewSafety(
                'emails.welcome-text',
                '/views/emails/welcome-text.blade.php',
                BladeTemplateAnalysis::unsafeKeys(['bio']),
            ),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeMethodEvent(
            \Illuminate\Mail\Mailable::class . '::text',
            [
                new Arg(new String_('emails.welcome-text')),
                new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSinkLabelExists($this->graphSinks($graph), "(emails.welcome-text, 'bio')");
    }

    #[Test]
    public function mail_message_view_installs_per_key_sink(): void
    {
        $this->initWithMap([
            'notification' => new BladeViewSafety(
                'notification',
                '/views/notification.blade.php',
                BladeTemplateAnalysis::unsafeKeys(['user']),
            ),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeMethodEvent(
            \Illuminate\Notifications\Messages\MailMessage::class . '::view',
            [
                new Arg(new String_('notification')),
                new Arg($this->arrayLiteral(['user' => $this->taintedVariable('user')])),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSinkLabelExists($this->graphSinks($graph), "(notification, 'user')");
    }

    #[Test]
    public function content_constructor_installs_sinks_for_each_view_slot(): void
    {
        // Content(?view, ?html, ?text, ?markdown, with = []). When view AND
        // text resolve to literal templates and both have an unsafe key
        // matching $with, one sink per view-slot fires.
        $this->initWithMap([
            'emails.welcome' => new BladeViewSafety(
                'emails.welcome',
                '/views/emails/welcome.blade.php',
                BladeTemplateAnalysis::unsafeKeys(['bio']),
            ),
            'emails.welcome-text' => new BladeViewSafety(
                'emails.welcome-text',
                '/views/emails/welcome-text.blade.php',
                BladeTemplateAnalysis::unsafeKeys(['bio']),
            ),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeMethodEvent(
            \Illuminate\Mail\Mailables\Content::class . '::__construct',
            [
                new Arg(new String_('emails.welcome')),
                new Arg(new String_('')),
                new Arg(new String_('emails.welcome-text')),
                new Arg(new String_('')),
                new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $sinks = $this->graphSinks($graph);

        // Both view slots (index 0 and 2) contribute a sink — markdown (index
        // 3) is the empty literal '' (treated as a literal string), but since
        // there's no 'emails.welcome-markdown' or matching name in the map,
        // it produces no sink. Test asserts only the two confirmed.
        $this->assertSinkLabelExists($sinks, "(emails.welcome, 'bio')");
        $this->assertSinkLabelExists($sinks, "(emails.welcome-text, 'bio')");
    }

    #[Test]
    public function factory_first_mixed_safe_and_unsafe_takes_union(): void
    {
        // 'a' is SAFE (contributes nothing), 'b' has unsafe key 'bio'. The
        // dispatcher must NOT short-circuit on SAFE and must take the
        // union, so 'bio' surfaces as the sole sink.
        $this->initWithMap([
            'a' => new BladeViewSafety('a', '/views/a.blade.php', BladeTemplateAnalysis::safe()),
            'b' => new BladeViewSafety('b', '/views/b.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeMethodEvent(\Illuminate\View\Factory::class . '::first', [
            new Arg($this->viewNameList(['a', 'b'])),
            new Arg($this->arrayLiteral([
                'bio' => $this->taintedVariable('bio'),
                'safe' => $this->taintedVariable('safe'),
            ])),
        ], $codebase);

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $sinks = $this->graphSinks($graph);

        $this->assertCount(1, $sinks);
        $this->assertSinkLabelExists($sinks, "'bio'");
    }

    #[Test]
    public function factory_first_with_mixed_unknown_in_map_and_missing_falls_back_to_whole_data(): void
    {
        // One candidate is in-map UNKNOWN (trips the UNKNOWN-fallback);
        // another is not-in-map (which on its own would silently skip).
        // The UNKNOWN should dominate regardless of iteration order; the
        // result is the whole-data fallback. Regression for the policy
        // boundary documented at dispatchFirstLike's "not in map →
        // continue" branch.
        $this->initWithMap([
            'inMapUnknown' => new BladeViewSafety(
                'inMapUnknown',
                '/views/inMapUnknown.blade.php',
                BladeTemplateAnalysis::unknown([BladeUncertaintyReason::LayoutSectionFlow]),
            ),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeMethodEvent(\Illuminate\View\Factory::class . '::first', [
            new Arg($this->viewNameList(['inMapUnknown', 'notInMap'])),
            new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
        ], $codebase);

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        // Whole-data fallback fires; the label carries the UNKNOWN
        // candidate's name (the first to trip the fallback).
        $this->assertSinkLabelExists($this->graphSinks($graph), "(inMapUnknown,");
    }

    #[Test]
    public function factory_first_empty_array_installs_no_sink(): void
    {
        // `first([], $data)` — zero candidates. Laravel throws at runtime
        // (InvalidArgumentException). At analysis time we install nothing:
        // there is no template to sink against. Documents the boundary.
        $this->initWithMap([]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeMethodEvent(\Illuminate\View\Factory::class . '::first', [
            new Arg($this->viewNameList([])),
            new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
        ], $codebase);

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSame([], $this->graphSinks($graph));
    }

    #[Test]
    public function view_first_chained_with_multiple_literal_candidates_skips_sink(): void
    {
        // Receiver-walk soundness: `View::first(['a', 'b'])->with('bio', $bio)`.
        // The receiver array carries multiple literal candidates; the
        // dispatcher cannot tell which Laravel will pick at runtime, so it
        // refuses resolution rather than picking one and risking a missed
        // XSS in the unpicked template.
        $this->initWithMap([
            'a' => new BladeViewSafety('a', '/views/a.blade.php', BladeTemplateAnalysis::safe()),
            'b' => new BladeViewSafety('b', '/views/b.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $firstCall = new StaticCall(
            new Name(\Illuminate\Support\Facades\View::class),
            'first',
            [new Arg($this->viewNameList(['a', 'b']))],
        );

        $event = $this->makeInstanceMethodEvent(
            \Illuminate\View\View::class . '::with',
            $firstCall,
            [
                new Arg(new String_('bio')),
                new Arg($this->taintedVariable('bio')),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSame([], $this->graphSinks($graph));
    }

    #[Test]
    public function view_with_array_form_installs_per_key_sinks(): void
    {
        // Laravel's `View::with($key, $value = null)` accepts $key as a
        // string OR array. The array form merges every entry into the view
        // data via `array_merge($this->data, $key)`. Without this dispatch
        // the array form would silently bypass taint analysis.
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $viewCall = new FuncCall(new Name('view'), [new Arg(new String_('post'))]);

        $event = $this->makeInstanceMethodEvent(
            \Illuminate\View\View::class . '::with',
            $viewCall,
            [
                new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSinkLabelExists($this->graphSinks($graph), "(post, 'bio')");
    }

    #[Test]
    public function view_with_array_form_on_unknown_template_installs_whole_data_sink(): void
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

        $viewCall = new FuncCall(new Name('view'), [new Arg(new String_('broken'))]);

        $event = $this->makeInstanceMethodEvent(
            \Illuminate\View\View::class . '::with',
            $viewCall,
            [
                new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertNotEmpty($this->graphSinks($graph));
    }

    #[Test]
    public function view_with_array_key_and_non_null_value_still_routes_to_array_dispatch(): void
    {
        // Laravel ignores `$value` when `$key` is an array, regardless of
        // `$value`'s shape: `with(['bio' => $tainted], $unused)` merges only
        // the array. The dispatcher must NOT sink on $unused.
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $viewCall = new FuncCall(new Name('view'), [new Arg(new String_('post'))]);

        $event = $this->makeInstanceMethodEvent(
            \Illuminate\View\View::class . '::with',
            $viewCall,
            [
                new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
                new Arg($this->taintedVariable('unusedSecondArg')),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $sinks = $this->graphSinks($graph);

        // Exactly one sink: the array's 'bio' entry. Not the unused $value.
        $this->assertCount(1, $sinks);
        $this->assertSinkLabelExists($sinks, "(post, 'bio')");
    }

    #[Test]
    public function view_with_string_key_and_explicit_null_value_installs_no_sink(): void
    {
        // `with('key', null)` — string key, explicit null value. Laravel
        // binds null to the literal key; no value flows. The array-form
        // gate must NOT misroute this through `installSinksForArg`, which
        // would otherwise install a `<argument>` whole-arg sink on the key
        // expression and produce a false positive when `$keyVar` is tainted.
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $viewCall = new FuncCall(new Name('view'), [new Arg(new String_('post'))]);

        $event = $this->makeInstanceMethodEvent(
            \Illuminate\View\View::class . '::with',
            $viewCall,
            [
                new Arg(new String_('bio')),
                new Arg(new \PhpParser\Node\Expr\ConstFetch(new Name('null'))),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        // Null value has no taintable parent_nodes, so even reaching
        // installSinkForExpression would short-circuit. Asserting the empty
        // sink list pins the no-FP boundary against future refactors that
        // could route the call through a whole-data fallback.
        $this->assertSame([], $this->graphSinks($graph));
    }

    #[Test]
    public function view_with_variable_key_and_explicit_null_value_installs_no_sink(): void
    {
        // `with($scalarKeyVar, null)` — variable key, explicit null value.
        // The dispatcher must NOT enter the array-form branch (the key is
        // not an array literal) and therefore must NOT install a sink on
        // the key variable. This is the Round 2 security finding fix.
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $viewCall = new FuncCall(new Name('view'), [new Arg(new String_('post'))]);

        $event = $this->makeInstanceMethodEvent(
            \Illuminate\View\View::class . '::with',
            $viewCall,
            [
                new Arg($this->taintedVariable('keyName')),
                new Arg(new \PhpParser\Node\Expr\ConstFetch(new Name('null'))),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSame([], $this->graphSinks($graph));
    }

    #[Test]
    public function view_with_array_form_with_explicit_null_value_routes_to_array_dispatch(): void
    {
        // `->with(['bio' => $tainted], null)` — explicit `null` $value with
        // array $key. Same array-form semantics as the 1-arg shape.
        $this->initWithMap([
            'post' => new BladeViewSafety('post', '/views/post.blade.php', BladeTemplateAnalysis::unsafeKeys(['bio'])),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $viewCall = new FuncCall(new Name('view'), [new Arg(new String_('post'))]);

        $event = $this->makeInstanceMethodEvent(
            \Illuminate\View\View::class . '::with',
            $viewCall,
            [
                new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
                new Arg(new \PhpParser\Node\Expr\ConstFetch(new Name('null'))),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSinkLabelExists($this->graphSinks($graph), "(post, 'bio')");
    }

    #[Test]
    public function content_constructor_with_explicit_null_view_slots_installs_no_dynamic_sink(): void
    {
        // `new Content(view: null, text: 'emails.welcome-text', markdown: null,
        // with: ['bio' => $tainted])` — opt out of two slots with literal
        // `null`. Each null slot must NOT install a `<dynamic>` whole-data
        // sink; only the text slot dispatches against its real safety record.
        $this->initWithMap([
            'emails.welcome-text' => new BladeViewSafety(
                'emails.welcome-text',
                '/views/emails/welcome-text.blade.php',
                BladeTemplateAnalysis::unsafeKeys(['bio']),
            ),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $nullConst = new \PhpParser\Node\Expr\ConstFetch(new Name('null'));

        $event = $this->makeMethodEvent(
            \Illuminate\Mail\Mailables\Content::class . '::__construct',
            [
                new Arg($nullConst), // view: null
                new Arg(new String_('')), // html (pre-rendered, unregistered)
                new Arg(new String_('emails.welcome-text')), // text: 'emails.welcome-text'
                new Arg($nullConst), // markdown: null
                new Arg($this->arrayLiteral(['bio' => $this->taintedVariable('bio')])),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $sinks = $this->graphSinks($graph);

        // Exactly one sink: the text slot's per-key sink. No <dynamic>
        // whole-data sinks from the null slots.
        $this->assertCount(1, $sinks);
        $this->assertSinkLabelExists($sinks, "(emails.welcome-text, 'bio')");
    }

    #[Test]
    public function view_nest_with_dynamic_nested_view_falls_back_to_dynamic(): void
    {
        $this->initWithMap([]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeMethodEvent(
            \Illuminate\View\View::class . '::nest',
            [
                new Arg(new String_('section')),
                new Arg($this->taintedVariable('dynamicNestedView')),
                new Arg($this->arrayLiteral(['html' => $this->taintedVariable('bio')])),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSinkLabelExists($this->graphSinks($graph), "(<dynamic>, 'html')");
    }

    #[Test]
    public function view_nest_installs_sink_on_nested_view_data(): void
    {
        // $factory->nest('section', 'partials.row', ['html' => $bio]). The
        // child template at index 1 is the safety lookup; data at index 2.
        $this->initWithMap([
            'partials.row' => new BladeViewSafety(
                'partials.row',
                '/views/partials/row.blade.php',
                BladeTemplateAnalysis::unsafeKeys(['html']),
            ),
        ]);

        $graph = new TaintFlowGraph();
        $codebase = $this->createCodebase($graph);

        $event = $this->makeMethodEvent(
            \Illuminate\View\View::class . '::nest',
            [
                new Arg(new String_('section')),
                new Arg(new String_('partials.row')),
                new Arg($this->arrayLiteral(['html' => $this->taintedVariable('bio')])),
            ],
            $codebase,
        );

        BladeAwareViewTaintHandler::afterMethodCallAnalysis($event);

        $this->assertSinkLabelExists($this->graphSinks($graph), "(partials.row, 'html')");
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
     * Build an {@see AfterMethodCallAnalysisEvent} backed by an instance
     * MethodCall (rather than the StaticCall used by {@see makeMethodEvent}).
     * The dispatcher's receiver-walk for `View::with` requires a real
     * `\PhpParser\Node\Expr\MethodCall` so it can inspect `$expr->var`.
     *
     * @param list<Arg> $args
     */
    private function makeInstanceMethodEvent(
        string $methodId,
        \PhpParser\Node\Expr $receiver,
        array $args,
        Codebase $codebase,
    ): AfterMethodCallAnalysisEvent {
        $methodName = \explode('::', $methodId)[1];

        $methodCall = new \PhpParser\Node\Expr\MethodCall($receiver, $methodName, $args);
        $methodCall->setAttribute('startFilePos', 0);
        $methodCall->setAttribute('endFilePos', 100);

        $source = $this->makeSource($args);

        return new AfterMethodCallAnalysisEvent(
            $methodCall,
            $methodId,
            $methodId,
            $methodId,
            new Context(),
            $source,
            $codebase,
        );
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

    /**
     * Build a list-style `[String_('a'), String_('b'), ...]` array literal
     * for `Factory::first()`'s candidate-view array. Distinct from
     * {@see arrayLiteral()} which expects associative `key => Expr` pairs.
     *
     * @param list<string> $names
     */
    private function viewNameList(array $names): Array_
    {
        $items = [];

        foreach ($names as $name) {
            $items[] = new ArrayItem(new String_($name));
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
