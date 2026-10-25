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
use Psalm\Context;
use Psalm\Internal\Provider\NodeDataProvider;
use Psalm\LaravelPlugin\Blade\Annotate\AnnotationCollector;
use Psalm\LaravelPlugin\Blade\ContractRegistry;
use Psalm\Plugin\EventHandler\Event\AfterStatementAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type;

#[CoversClass(AnnotationCollector::class)]
final class AnnotationCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        AnnotationCollector::reset();
        ContractRegistry::reset();
    }

    protected function tearDown(): void
    {
        AnnotationCollector::reset();
        ContractRegistry::reset();
    }

    #[Test]
    public function answers_the_type_a_single_resolved_producer_passed(): void
    {
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
    public function merges_up_to_three_classes_and_declines_a_fourth(): void
    {
        [$a, $b, $c, $d] = [$this->objects('App\A'), $this->objects('App\B'), $this->objects('App\C'), $this->objects('App\D')];

        $this->assertSame('App\A|App\B|App\C', $this->merge($a, $b, $c));
        $this->assertNull($this->merge($a, $b, $c, $d));
        $this->assertSame('App\A|App\B|App\C|null', $this->merge($a, $b, $this->objects('App\C', 'null')), '`null` is not counted');
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
    public function never_merges_an_array_with_an_object(): void
    {
        $this->assertNull($this->merge(Type::getList(Type::getString()), $this->objects('App\Post')));
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
    public function collapses_literal_precision_so_two_literals_of_a_type_still_agree(): void
    {
        AnnotationCollector::record('home', ['count' => $this->literal(1)], true);
        AnnotationCollector::record('home', ['count' => $this->literal(2)], true);

        $this->assertSame('int', AnnotationCollector::typeFor('home', 'count'));
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
    public function declines_a_type_whose_id_would_terminate_the_docblock(): void
    {
        // `@var array{'*/': string} $payload` closes the docblock at the key, and the rest of the
        // declaration becomes PHP in the template.
        $keyed = new Type\Union([Type\Atomic\TKeyedArray::make(['*/' => Type::getString()])]);

        AnnotationCollector::record('home', ['payload' => $keyed], true);

        $this->assertNull(AnnotationCollector::typeFor('home', 'payload'));
    }

    /**
     * Blade pairs `@verbatim` with `@endverbatim` and `@php` with `@endphp` across the whole file before
     * it protects the leading `<?php ?>` block, so a shape key spelling one opener inside the written
     * `@var` swallows template text up to the next closer and corrupts the header.
     *
     * Checked on the printed id directly: a composite id only round-trips through a live Psalm
     * (pinned end to end in AnnotateEndToEndTest), which is what `typeFor()` asks next.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function idsAgainstBladeBlocks(): iterable
    {
        foreach (['@verbatim', '@endverbatim', '@php', '@endphp', '@PHP', '@phpx'] as $delimiter) {
            yield "key {$delimiter}" => ["array{'{$delimiter}': string}", true];
        }

        yield 'in a nested shape' => ["list<array{id: int, 'a@php': string}>", true];
        yield 'a key that only looks like a directive' => ["array{'@context': string}", false];
        yield 'an email-like key' => ["array{'a@b.c': string}", false];
        yield 'a plain shape' => ['array{php: string}', false];
    }

    #[Test]
    #[DataProvider('idsAgainstBladeBlocks')]
    public function recognises_an_id_blade_would_pair_with_a_block_closer(string $id, bool $pairs): void
    {
        $this->assertSame($pairs, (new \ReflectionMethod(AnnotationCollector::class, 'pairsWithBladeBlock'))->invoke(null, $id));
    }

    #[Test]
    public function declines_a_key_a_view_composer_may_overwrite(): void
    {
        // The call site's type is not what the template reads once a composer writes the key.
        AnnotationCollector::record('home', ['title' => Type::getString(), 'other' => Type::getString()], true);
        ContractRegistry::registerComposerSources(['home' => [['App\\Composer', 'compose']]]);
        ContractRegistry::registerComposedKeys(['home' => ['title' => true]]);

        $this->assertNull(AnnotationCollector::typeFor('home', 'title'));
        $this->assertSame('string', AnnotationCollector::typeFor('home', 'other'), 'a key no composer writes keeps its type');
    }

    #[Test]
    public function declines_every_key_when_a_view_composer_cannot_be_read(): void
    {
        AnnotationCollector::record('home', ['title' => Type::getString()], true);
        ContractRegistry::registerComposerSources(['home' => null]);

        $this->assertNull(AnnotationCollector::typeFor('home', 'title'));
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
    public function marks_a_view_unreadable_when_a_rendering_shape_could_not_be_resolved(): void
    {
        // `$v = view('home', [...])` is declined by the chain walk, so its data is invisible; the
        // types the readable call sites agreed on are only part of the picture.
        AnnotationCollector::record('home', ['title' => Type::getString()], true);
        AnnotationCollector::markUnreadable('home');

        $this->assertNull(AnnotationCollector::typeFor('home', 'title'));
    }

    #[Test]
    public function keeps_the_smallest_unreadable_site_whatever_the_analysis_order(): void
    {
        $this->assertNull(AnnotationCollector::unreadableSite('home'));

        AnnotationCollector::markUnreadable('home');
        $this->assertSame('', AnnotationCollector::unreadableSite('home'));

        AnnotationCollector::markUnreadable('home', '/app/B.php:3');
        AnnotationCollector::record('home', [], false, '/app/A.php:10');
        AnnotationCollector::markUnreadable('home', '/app/A.php:9');
        AnnotationCollector::markUnreadable('home');

        $this->assertSame('/app/A.php:9', AnnotationCollector::unreadableSite('home'));
    }

    #[Test]
    public function reports_a_name_a_provably_closed_call_site_leaves_out(): void
    {
        // Declaring it would report MissingViewData at that very call site, which passes a
        // provably closed data set without it.
        AnnotationCollector::record('home', ['title' => Type::getString()], true);
        AnnotationCollector::record('home', [], true);

        $this->assertTrue(AnnotationCollector::isOptional('home', 'title'));
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
    public function records_an_isolated_include_site_as_a_producer_of_the_included_view(): void
    {
        $this->analyzeStatements("echo \$__env->make('inner', ['title' => 'text'])->render();", [['title' => Type::getString()]]);

        $this->assertSame('string', AnnotationCollector::typeFor('inner', 'title'));
    }

    #[Test]
    public function marks_a_view_unreadable_when_an_include_passes_the_including_scope_or_unreadable_data(): void
    {
        $scope = "array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1])";

        foreach (
            [
                "echo \$__env->make('inner', {$scope})->render();",
                "echo \$__env->make('inner', \$data)->render();",
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
        yield 'offset write in a tap callback' => ["tap((fn () => view('home', ['title' => 'text']))(), fn (\$v) => \$v['title'] = 42);"];
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
        // The `view()` helper check reads the file's `use function` imports.
        $source->method('getAliases')->willReturn(new \Psalm\Aliases());

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

    /** Built field by field: the event's constructor also wants a Codebase this hook never reads. */
    private function statementEvent(Stmt $statement, StatementsSource $source): AfterStatementAnalysisEvent
    {
        $class = new \ReflectionClass(AfterStatementAnalysisEvent::class);
        $event = $class->newInstanceWithoutConstructor();

        $class->getProperty('stmt')->setValue($event, $statement);
        $class->getProperty('context')->setValue($event, new Context());
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
