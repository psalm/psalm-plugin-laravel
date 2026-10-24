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
            $nodeData->setType($literal, new Type\Union([Type\Atomic\TKeyedArray::make($shapes[$index])]));
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

    /** Built directly: Type::getInt($value) reaches for a Config singleton no unit test boots. */
    private function literal(int $value): Type\Union
    {
        return new Type\Union([new Type\Atomic\TLiteralInt($value)]);
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
