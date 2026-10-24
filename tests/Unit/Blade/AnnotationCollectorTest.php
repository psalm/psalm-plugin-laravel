<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Internal\Provider\NodeDataProvider;
use Psalm\LaravelPlugin\Blade\Annotate\AnnotationCollector;
use Psalm\Plugin\EventHandler\Event\AfterStatementAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type;

#[CoversClass(AnnotationCollector::class)]
final class AnnotationCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        AnnotationCollector::reset();
    }

    protected function tearDown(): void
    {
        AnnotationCollector::reset();
    }

    #[Test]
    public function answers_the_type_a_single_resolved_producer_passed(): void
    {
        AnnotationCollector::record('home', ['title' => Type::getString()], true);

        $this->assertSame('string', AnnotationCollector::typeFor('home', 'title'));
    }

    #[Test]
    public function answers_the_type_every_producer_agrees_on(): void
    {
        AnnotationCollector::record('home', ['title' => Type::getString()], true);
        AnnotationCollector::record('home', ['title' => Type::getString()], true);

        $this->assertSame('string', AnnotationCollector::typeFor('home', 'title'));
    }

    #[Test]
    public function declines_when_two_producers_disagree(): void
    {
        AnnotationCollector::record('home', ['title' => Type::getString()], true);
        AnnotationCollector::record('home', ['title' => Type::getInt()], true);

        $this->assertNull(AnnotationCollector::typeFor('home', 'title'));
    }

    #[Test]
    public function merges_producers_that_differ_only_by_nullability(): void
    {
        $this->assertSame('App\User|null', $this->merge($this->objects('App\User'), Type::getNull()));
        $this->assertSame('App\User|null', $this->merge($this->objects('App\User'), $this->objects('App\User', 'null')));
        $this->assertSame('int|null', $this->merge(Type::getInt(), $this->objects('null')));
    }

    #[Test]
    public function merges_sibling_classes_into_their_union(): void
    {
        $this->assertSame('App\Post|App\Video|null', $this->merge($this->objects('App\Post'), $this->objects('App\Video', 'null')));
    }

    #[Test]
    public function merges_up_to_three_classes_and_declines_a_fourth(): void
    {
        [$a, $b, $c, $d] = [$this->objects('App\A'), $this->objects('App\B'), $this->objects('App\C'), $this->objects('App\D')];

        $this->assertSame('App\A|App\B|App\C', $this->merge($a, $b, $c));
        $this->assertNull($this->merge($a, $b, $c, $d));
        $this->assertSame('App\A|App\B|App\C|null', $this->merge($a, $b, $this->objects('App\C', 'null')), '`null` is not counted');
    }

    #[Test]
    public function reaches_the_same_verdict_in_any_producer_order(): void
    {
        $types = [$this->objects('App\A'), $this->objects('App\B', 'null'), $this->objects('App\C'), $this->objects('App\D')];

        foreach ([[0, 1, 2], [2, 0, 1], [1, 2, 0]] as $order) {
            $this->assertSame('App\A|App\B|App\C|null', $this->merge(...\array_map(static fn(int $i): Type\Union => $types[$i], $order)));
        }

        foreach ([[0, 1, 2, 3], [3, 0, 2, 1], [1, 3, 0, 2]] as $index => $order) {
            foreach ($order as $position) {
                AnnotationCollector::record("home{$index}", ['item' => $types[$position]], true);
            }

            $this->assertSame(['App\A', 'App\B|null', 'App\C', 'App\D'], AnnotationCollector::disagreeingTypes("home{$index}", 'item'));
        }
    }

    #[Test]
    public function never_merges_with_a_mixed_producer(): void
    {
        AnnotationCollector::record('home', ['item' => $this->objects('App\Post')], true);
        AnnotationCollector::record('home', ['item' => Type::getMixed()], true);

        $this->assertNull(AnnotationCollector::typeFor('home', 'item'));
        $this->assertSame(['App\Post', 'mixed'], AnnotationCollector::disagreeingTypes('home', 'item'));
    }

    #[Test]
    public function never_merges_two_scalar_kinds(): void
    {
        AnnotationCollector::record('home', ['key' => Type::getInt()], true);
        AnnotationCollector::record('home', ['key' => Type::getString()], true);

        $this->assertNull(AnnotationCollector::typeFor('home', 'key'));
        $this->assertSame(['int', 'string'], AnnotationCollector::disagreeingTypes('home', 'key'));
    }

    #[Test]
    public function never_merges_an_array_with_an_object(): void
    {
        $this->assertNull($this->merge(Type::getList(Type::getString()), $this->objects('App\Post')));
    }

    #[Test]
    public function never_merges_a_scalar_with_an_object(): void
    {
        $this->assertNull($this->merge(Type::getString(), $this->objects('App\Post')));
    }

    #[Test]
    public function does_not_list_producers_it_merged_as_disagreeing(): void
    {
        // The declaration a merge writes is a union, whose id needs a live Psalm to round-trip, so
        // the written form is pinned end to end (AnnotateEndToEndTest).
        AnnotationCollector::record('home', ['user' => $this->objects('App\User')], true);
        AnnotationCollector::record('home', ['user' => $this->objects('App\User', 'null')], true);

        $this->assertNull(AnnotationCollector::disagreeingTypes('home', 'user'));
    }

    #[Test]
    public function merges_one_class_with_different_generic_params_as_a_union_of_each(): void
    {
        // Psalm keeps them apart rather than writing `Collection<int, Post|Video>`, which an invariant
        // TValue would reject at both call sites. Each counts against the cap.
        $collection = fn(string $class): Type\Union => new Type\Union([
            new Type\Atomic\TGenericObject('Illuminate\Support\Collection', [Type::getInt(), $this->objects($class)]),
        ]);

        $this->assertSame(
            'Illuminate\Support\Collection<int, App\Post>|Illuminate\Support\Collection<int, App\Video>',
            $this->merge($collection('App\Post'), $collection('App\Video')),
        );
        $this->assertNull($this->merge($collection('App\A'), $collection('App\B'), $collection('App\C'), $collection('App\D')));
    }

    #[Test]
    public function declines_a_merge_beyond_a_plain_union_it_cannot_prove_holds(): void
    {
        // `bool` contains `true`, but saying so takes a codebase, and a unit test has none.
        $this->assertNull($this->merge(new Type\Union([new Type\Atomic\TTrue()]), new Type\Union([new Type\Atomic\TFalse()])));
    }

    #[Test]
    public function lists_no_disagreement_for_agreeing_producers_or_an_open_view(): void
    {
        AnnotationCollector::record('home', ['title' => Type::getString()], true);
        AnnotationCollector::record('home', ['title' => Type::getString()], true);
        AnnotationCollector::record('open', ['key' => Type::getInt()], true);
        AnnotationCollector::record('open', ['key' => Type::getString()], false);

        $this->assertNull(AnnotationCollector::disagreeingTypes('home', 'title'));
        $this->assertNull(AnnotationCollector::disagreeingTypes('open', 'key'), 'an open data set is not a disagreement');
    }

    #[Test]
    public function collapses_literal_precision_so_two_literals_of_a_type_still_agree(): void
    {
        AnnotationCollector::record('home', ['count' => $this->literal(1)], true);
        AnnotationCollector::record('home', ['count' => $this->literal(2)], true);

        $this->assertSame('int', AnnotationCollector::typeFor('home', 'count'));
    }

    #[Test]
    public function keeps_every_literal_that_shares_an_id_for_the_containment_check(): void
    {
        // `100` and `1` share the id `int`. Keeping only the last would let a later `int<0, 5>`
        // merge to a range that rejects the `100` call site.
        AnnotationCollector::record('home', ['count' => $this->literal(100)], true);
        AnnotationCollector::record('home', ['count' => $this->literal(1)], true);

        $observed = (new \ReflectionProperty(AnnotationCollector::class, 'observed'))->getValue();
        $this->assertIsArray($observed['home']['count']);
        $kept = \array_keys($observed['home']['count']['int']->getAtomicTypes());
        \sort($kept);

        $this->assertSame(['int(1)', 'int(100)'], $kept);
    }

    #[Test]
    public function declines_for_every_name_once_one_producer_of_the_view_was_unreadable(): void
    {
        AnnotationCollector::record('home', ['title' => Type::getString()], true);
        AnnotationCollector::record('home', [], false);

        $this->assertNull(
            AnnotationCollector::typeFor('home', 'title'),
            'an open data set can carry the same key with another type',
        );
    }

    #[Test]
    public function declines_for_a_view_no_producer_resolved(): void
    {
        $this->assertNull(AnnotationCollector::typeFor('home', 'title'));
    }

    #[Test]
    public function declines_for_a_name_no_producer_passed(): void
    {
        AnnotationCollector::record('home', ['title' => Type::getString()], true);

        $this->assertNull(AnnotationCollector::typeFor('home', 'body'));
    }

    #[Test]
    public function keeps_a_numeric_view_name_addressable_as_a_string(): void
    {
        // PHP casts a numeric-string array key to int, and `123.blade.php` is a legal template.
        AnnotationCollector::record('123', ['title' => Type::getString()], true);

        $this->assertSame('string', AnnotationCollector::typeFor('123', 'title'));
    }

    #[Test]
    public function declines_a_type_that_does_not_survive_a_parse_round_trip(): void
    {
        // An anonymous class prints an id carrying its defining file and line, which the
        // `@var` reader on the next run cannot parse back.
        $anonymous = new Type\Union([new Type\Atomic\TNamedObject('Foo@anonymous/var/www/x.php:3$0')]);

        AnnotationCollector::record('home', ['title' => $anonymous], true);

        $this->assertNull(AnnotationCollector::typeFor('home', 'title'));
    }

    #[Test]
    public function forgets_everything_on_reset(): void
    {
        AnnotationCollector::record('home', ['title' => Type::getString()], true);
        AnnotationCollector::reset();

        $this->assertNull(AnnotationCollector::typeFor('home', 'title'));
    }

    #[Test]
    public function declines_a_type_whose_id_would_terminate_the_docblock(): void
    {
        // `@var array{'*/': string} $payload` closes the docblock at the key, and the rest of the
        // declaration becomes PHP in the template.
        $keyed = new Type\Union([Type\Atomic\TKeyedArray::make(['*/' => Type::getString()])]);

        AnnotationCollector::record('home', ['payload' => $keyed], true);

        $this->assertNull(AnnotationCollector::typeFor('home', 'payload'));
    }

    #[Test]
    public function declines_a_template_parameter_even_though_its_id_parses(): void
    {
        // `T` parses, but as a class named T — a different type than the one observed.
        $param = new Type\Atomic\TTemplateParam('T', Type::getMixed(), 'fn-foo');

        AnnotationCollector::record('home', ['row' => new Type\Union([$param])], true);

        $this->assertNull(AnnotationCollector::typeFor('home', 'row'));
    }

    #[Test]
    public function declines_a_template_parameter_nested_inside_a_generic(): void
    {
        $param = new Type\Union([new Type\Atomic\TTemplateParam('T', Type::getMixed(), 'fn-foo')]);
        $generic = new Type\Atomic\TGenericObject('Illuminate\Support\Collection', [Type::getInt(), $param]);

        AnnotationCollector::record('home', ['rows' => new Type\Union([$generic])], true);

        $this->assertNull(AnnotationCollector::typeFor('home', 'rows'));
    }

    #[Test]
    public function marks_a_view_unreadable_when_a_rendering_shape_could_not_be_resolved(): void
    {
        // `$v = view('home', [...])` is declined by the chain walk, so its data is invisible; the
        // types the readable call sites agreed on are only part of the picture.
        AnnotationCollector::record('home', ['title' => Type::getString()], true);
        AnnotationCollector::markUnreadable('home');

        $this->assertNull(AnnotationCollector::typeFor('home', 'title'));
    }

    #[Test]
    public function reports_a_name_a_provably_closed_call_site_leaves_out(): void
    {
        // Declaring it would report MissingViewVariable at that very call site, which passes a
        // provably closed data set without it.
        AnnotationCollector::record('home', ['title' => Type::getString()], true);
        AnnotationCollector::record('home', [], true);

        $this->assertTrue(AnnotationCollector::isOptional('home', 'title'));
    }

    #[Test]
    public function does_not_report_a_name_every_closed_call_site_passes(): void
    {
        AnnotationCollector::record('home', ['title' => Type::getString()], true);
        AnnotationCollector::record('home', ['title' => Type::getString()], true);

        $this->assertFalse(AnnotationCollector::isOptional('home', 'title'));
    }

    #[Test]
    public function does_not_report_a_name_when_no_call_site_proved_its_key_set_closed(): void
    {
        AnnotationCollector::record('home', [], false);

        $this->assertFalse(AnnotationCollector::isOptional('home', 'title'));
    }

    /**
     * The confidence the direct call site alone establishes. Asserted so the companion test below
     * cannot pass because the fixture failed to record anything.
     */
    #[Test]
    public function answers_the_type_of_a_statement_the_chain_walk_resolved(): void
    {
        $this->analyzeStatements("view('home', ['title' => 'text']);", [['title' => Type::getString()]]);

        $this->assertSame('string', AnnotationCollector::typeFor('home', 'title'));
    }

    #[Test]
    public function marks_a_view_unreadable_when_a_nested_rendering_call_was_declined(): void
    {
        // The chain walk starts at the outermost expression, so the assignment is declined whole
        // and the int it passes is invisible. Only the scan for view names nested inside a declined
        // expression stops the string from being declared as if nothing disagreed.
        $this->analyzeStatements(
            "view('home', ['title' => 'text']);\n\$v = view('home', ['title' => 42]);",
            [['title' => Type::getString()], ['title' => $this->literal(42)]],
        );

        $this->assertNull(AnnotationCollector::typeFor('home', 'title'));
    }

    #[Test]
    public function marks_a_view_unreadable_when_the_declined_call_names_its_view_argument(): void
    {
        // Named arguments may come in any order, so the view name is not always argument zero.
        $this->analyzeStatements(
            "view('home', ['title' => 'text']);\n\$v = view(data: ['title' => 42], view: 'home');",
            [['title' => Type::getString()], ['title' => $this->literal(42)]],
        );

        $this->assertNull(AnnotationCollector::typeFor('home', 'title'));
    }

    #[Test]
    public function records_an_isolated_include_site_as_a_producer_of_the_included_view(): void
    {
        $this->analyzeStatements("echo \$__env->make('inner', ['title' => 'text'])->render();", [['title' => Type::getString()]]);

        $this->assertSame('string', AnnotationCollector::typeFor('inner', 'title'));
    }

    #[Test]
    public function declines_when_an_include_site_disagrees_with_a_view_call_site(): void
    {
        $this->analyzeStatements(
            "view('inner', ['title' => 'text']);\necho \$__env->make('inner', ['title' => \$n])->render();",
            [['title' => Type::getString()], ['title' => $this->literal(42)]],
        );

        $this->assertNull(AnnotationCollector::typeFor('inner', 'title'));
    }

    #[Test]
    public function marks_a_view_unreadable_when_an_include_passes_the_including_scope_or_unreadable_data(): void
    {
        $scope = "array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1])";

        foreach (
            [
                "echo \$__env->make('inner', {$scope})->render();",
                "echo \$__env->make('inner', ['title' => 1], {$scope})->render();",
                "echo \$__env->make('inner', \$data)->render();",
                "echo \$__env->renderWhen(\$on, 'inner', {$scope});",
                "echo \$__env->first(['missing', 'inner'], {$scope})->render();",
                "echo \$__env->renderEach('inner', \$items, 'item');",
            ] as $include
        ) {
            AnnotationCollector::reset();
            $this->analyzeStatements(
                "view('inner', ['title' => 'text']);\n{$include}",
                [['title' => Type::getString()], [], [], []],
            );

            $this->assertNull(AnnotationCollector::typeFor('inner', 'title'), $include);
        }
    }

    #[Test]
    public function marks_the_empty_view_of_an_each_unreadable_but_not_a_raw_one(): void
    {
        // `renderEach()` renders the empty view with no data at all.
        $this->analyzeStatements(
            "view('empty', ['title' => 'text']);\nview('raw|empty', ['title' => 'text']);\n"
            . "echo \$__env->renderEach('row', \$items, 'item', 'empty');\necho \$__env->renderEach('row', \$items, 'item', 'raw|empty');",
            [['title' => Type::getString()], ['title' => Type::getString()]],
        );

        $this->assertNull(AnnotationCollector::typeFor('empty', 'title'));
        $this->assertSame('string', AnnotationCollector::typeFor('raw|empty', 'title'), 'a raw| string is text, not a view');
    }

    #[Test]
    public function marks_a_component_target_unreadable(): void
    {
        foreach (
            [
                "\$__env->startComponent('inner', ['title' => 42]);",
                "\$__env->startComponentFirst(['missing', 'inner'], ['title' => 42]);",
                "\$component = Illuminate\\View\\AnonymousComponent::resolve(['view' => 'inner', 'data' => ['title' => 42]]"
                    . " + (isset(\$attributes) ? \$attributes->all() : []));",
            ] as $component
        ) {
            AnnotationCollector::reset();
            $this->analyzeStatements("view('inner', ['title' => 'text']);\n{$component}", [['title' => Type::getString()]]);

            $this->assertNull(AnnotationCollector::typeFor('inner', 'title'), $component);
        }
    }

    #[Test]
    public function still_reads_a_rendering_call_nested_in_a_recorded_producer(): void
    {
        foreach (
            [
                "echo \$__env->make('inner', ['title' => view('inner', ['title' => 42])->render()])->render();",
                "view('inner', ['title' => view('inner', ['title' => 42])->render()]);",
            ] as $nested
        ) {
            AnnotationCollector::reset();
            $this->analyzeStatements($nested, [['title' => Type::getString()], ['title' => $this->literal(42)]]);

            $this->assertNull(AnnotationCollector::typeFor('inner', 'title'), $nested);
        }
    }

    #[Test]
    public function an_include_lookalike_rendering_with_a_callback_is_not_recorded_as_closed(): void
    {
        $this->analyzeStatements(
            "echo \$__env->make('inner', ['title' => 'text'])->render(static fn (\$v) => \$v->with('title', 42)->render());",
            [['title' => Type::getString()]],
        );

        $this->assertNull(AnnotationCollector::typeFor('inner', 'title'));
    }

    #[Test]
    public function a_slash_and_a_dot_view_name_are_one_view(): void
    {
        $this->analyzeStatements("echo \$__env->make('cards.inner', ['title' => 'text'])->render();", [['title' => Type::getString()]]);

        $this->assertSame('string', AnnotationCollector::typeFor('cards/inner', 'title'));

        $this->analyzeStatements("echo \$__env->make('cards/inner', ['title' => \$n])->render();", [['title' => $this->literal(42)]]);

        $this->assertNull(AnnotationCollector::typeFor('cards.inner', 'title'));
        $this->assertNull(AnnotationCollector::typeFor('cards/inner', 'title'));
    }

    #[Test]
    public function a_raw_prefixed_name_is_an_ordinary_view_outside_the_each_empty_argument(): void
    {
        $this->analyzeStatements(
            "echo \$__env->make('raw|inner', ['title' => 'text'])->render();\necho view('raw|inner', ['title' => 42])->render();",
            [['title' => Type::getString()], ['title' => $this->literal(42)]],
        );

        $this->assertNull(AnnotationCollector::typeFor('raw|inner', 'title'));
    }

    #[Test]
    public function answers_the_type_a_callback_inside_a_declined_chain_passes(): void
    {
        // The callback's statement records the producer first; the declined chain holding it then
        // meets the same call, `with()` link included, and must not count it again as unreadable.
        $this->analyzeStatements(
            "Action::make('qr')->modalContent(fn () => view('home', ['title' => 'text'])->with(['extra' => 'more']));",
            [['title' => Type::getString()], ['extra' => Type::getString()]],
        );

        $this->assertSame('string', AnnotationCollector::typeFor('home', 'title'));
        $this->assertSame('string', AnnotationCollector::typeFor('home', 'extra'));
    }

    #[Test]
    public function marks_a_view_unreadable_when_a_callback_renders_it_through_a_declined_chain(): void
    {
        // The callback's own statement is declined too (`render()` is no link the walk knows), so no
        // producer was recorded for that call and the outer scan still has to count it.
        $this->analyzeStatements(
            "view('home', ['title' => 'text']);\nAction::make('qr')->modalContent(fn () => view('home', ['title' => 42])->render());",
            [['title' => Type::getString()], ['title' => $this->literal(42)]],
        );

        $this->assertNull(AnnotationCollector::typeFor('home', 'title'));
    }

    /**
     * The callback's call was recorded, but the declined expression around it receives the view it
     * returns and may write data into it: invoked in place, through a helper, by a magic `with*`
     * call, or by an offset write.
     *
     * @return iterable<string, array{string}>
     */
    public static function callbackResultWrites(): iterable
    {
        yield 'invoked in place, with()' => ["(fn () => view('home', ['title' => 'text']))()->with('title', 42);"];
        yield 'through a helper, magic with*()' => ["value(fn () => view('home', ['title' => 'text']))->withTitle(42);"];
        yield 'offset write in a tap callback' => ["tap((fn () => view('home', ['title' => 'text']))(), fn (\$v) => \$v['title'] = 42);"];
        yield 'dynamic method name' => ["value(fn () => view('home', ['title' => 'text']))->{\$method}('title', 42);"];
    }

    #[Test]
    #[DataProvider('callbackResultWrites')]
    public function marks_a_view_unreadable_when_the_declined_expression_may_write_into_a_callback_result(string $code): void
    {
        $this->analyzeStatements($code, [['title' => Type::getString()]]);

        $this->assertNull(AnnotationCollector::typeFor('home', 'title'));
    }

    /**
     * Runs the statement hook over parsed PHP, with the shape each array literal infers to supplied
     * in source order: no analysis runs here, so nothing else would type the data arguments.
     *
     * Callback bodies are fed ahead of the statement holding them, innermost first, which is the
     * order Psalm analyses them in.
     *
     * @param list<array<string, Type\Union>> $shapes
     */
    private function analyzeStatements(string $code, array $shapes): void
    {
        AnnotationCollector::init();

        $statements = (new ParserFactory())->createForHostVersion()->parse("<?php\n" . $code);
        $this->assertIsArray($statements);

        $nodeData = new NodeDataProvider();

        foreach ((new NodeFinder())->findInstanceOf($statements, Expr\Array_::class) as $index => $literal) {
            $nodeData->setType($literal, new Type\Union([Type\Atomic\TKeyedArray::make($shapes[$index] ?? ['x' => Type::getString()])]));
        }

        // What every compiled template's prelude types `$__env` as.
        foreach ((new NodeFinder())->findInstanceOf($statements, Expr\Variable::class) as $variable) {
            if ($variable->name === '__env') {
                $nodeData->setType($variable, new Type\Union([new Type\Atomic\TNamedObject(\Illuminate\View\Factory::class)]));
            }
        }

        $source = $this->createStub(StatementsSource::class);
        $source->method('getNodeTypeProvider')->willReturn($nodeData);

        foreach ($statements as $statement) {
            $callbacks = (new NodeFinder())->find(
                $statement,
                static fn(Node $node): bool => $node instanceof Expr\ArrowFunction || $node instanceof Expr\Closure,
            );

            foreach (\array_reverse($callbacks) as $callback) {
                \assert($callback instanceof Expr\ArrowFunction || $callback instanceof Expr\Closure);

                foreach ($callback->getStmts() as $inner) {
                    AnnotationCollector::afterStatementAnalysis($this->statementEvent($inner, $source));
                }
            }

            AnnotationCollector::afterStatementAnalysis($this->statementEvent($statement, $source));
        }
    }

    /** Built field by field: the event's constructor also wants a Context and a Codebase this hook never reads. */
    private function statementEvent(Stmt $statement, StatementsSource $source): AfterStatementAnalysisEvent
    {
        $class = new \ReflectionClass(AfterStatementAnalysisEvent::class);
        $event = $class->newInstanceWithoutConstructor();

        $class->getProperty('stmt')->setValue($event, $statement);
        $class->getProperty('statements_source')->setValue($event, $source);

        return $event;
    }

    /** What the producers merge to, by id, or null when they do not merge. */
    private function merge(Type\Union ...$types): ?string
    {
        $distinct = [];

        foreach ($types as $type) {
            $distinct[$type->getId(false)] = $type;
        }

        $merged = (new \ReflectionMethod(AnnotationCollector::class, 'merged'))->invoke(null, $distinct);

        return $merged instanceof Type\Union ? $merged->getId(false) : null;
    }

    /** A union of named classes, `null` standing for the null type. */
    private function objects(string ...$classes): Type\Union
    {
        return new Type\Union(\array_map(
            static fn(string $class): Type\Atomic => $class === 'null' ? new Type\Atomic\TNull() : new Type\Atomic\TNamedObject($class),
            $classes,
        ));
    }

    /** Built directly: Type::getInt($value) reaches for a Config singleton no unit test boots. */
    private function literal(int $value): Type\Union
    {
        return new Type\Union([new Type\Atomic\TLiteralInt($value)]);
    }

    #[Test]
    public function prints_an_unbounded_int_range_as_int_at_any_depth(): void
    {
        $int = new Type\Union([new Type\Atomic\TIntRange(null, null)]);
        $passed = new Type\Union([Type\Atomic\TKeyedArray::make([
            'box' => new Type\Union([new Type\Atomic\TGenericObject('App\\Box', [$int])]),
            'counts' => new Type\Union([new Type\Atomic\TArray([Type::getArrayKey(), $int])]),
            'total' => $int,
            'positive' => new Type\Union([new Type\Atomic\TIntRange(1, null)]),
        ])]);

        // typeFor() also parses the id back, which needs a booted Psalm; the printed form is what matters.
        $normalize = new \ReflectionMethod(AnnotationCollector::class, 'withoutUnboundedIntRange');
        $printed = $normalize->invoke(null, $passed);
        $this->assertInstanceOf(Type\Union::class, $printed);

        $this->assertSame('array{box: App\\Box<int>, counts: array<array-key, int>, positive: int<1, max>, total: int}', $printed->getId(false));
        $this->assertSame(
            'array{box: App\\Box<int<min, max>>, counts: array<array-key, int<min, max>>, positive: int<1, max>, total: int<min, max>}',
            $passed->getId(false),
            'the inferred type itself is left untouched',
        );
    }

    #[Test]
    public function an_unbounded_int_range_agrees_with_int(): void
    {
        AnnotationCollector::record('home', ['total' => new Type\Union([new Type\Atomic\TIntRange(null, null)])], true);
        AnnotationCollector::record('home', ['total' => new Type\Union([new Type\Atomic\TInt()])], true);

        $this->assertSame('int', AnnotationCollector::typeFor('home', 'total'));
    }

    #[Test]
    public function writes_class_names_absolute_so_a_template_import_cannot_change_them(): void
    {
        $object = new Type\Union([new Type\Atomic\TNamedObject('App\\Models\\User')]);

        AnnotationCollector::record('home', ['user' => $object], true);

        $this->assertSame('\\App\\Models\\User', AnnotationCollector::typeFor('home', 'user'));

        // Composite ids need a live Psalm to round-trip, so the rewrite itself is checked directly.
        $absolute = new \ReflectionMethod(AnnotationCollector::class, 'withAbsoluteClassNames');

        $this->assertSame(
            "list<\\App\\User>|\\Closure(int): string|array{User: \\App\\U, 'App\\U': int}",
            $absolute->invoke(null, "list<App\\User>|Closure(int): string|array{User: App\\U, 'App\\U': int}"),
            'class names gain a backslash; shape keys, literals and keywords do not',
        );
    }
}
