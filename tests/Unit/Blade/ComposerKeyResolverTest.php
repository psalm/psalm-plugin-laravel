<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PhpParser\Node\Stmt\Class_;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ComposerKeyResolver;

/**
 * The key set may only ever be too BIG: a key left out is a false positive on the user's call site,
 * a key wrongly added only costs a missed report.
 */
#[CoversClass(ComposerKeyResolver::class)]
final class ComposerKeyResolverTest extends TestCase
{
    /**
     * @param array<string, true>|null $expected
     */
    #[Test]
    #[DataProvider('bodies')]
    public function it_reads_the_keys_a_compose_body_supplies(string $body, ?array $expected, string $params = '$view'): void
    {
        $class = (new ParserFactory())->createForNewestSupportedVersion()->parse("<?php class C { function compose({$params}) { {$body} } }")[0] ?? null;
        $this->assertInstanceOf(Class_::class, $class);

        $this->assertSame($expected, ComposerKeyResolver::keysIn($class->getMethods()[0]));
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, true>|null, 2?: string}>
     */
    public static function bodies(): iterable
    {
        yield 'single key' => ['$view->with("title", 1);', ['title' => true]];
        yield 'single key without a value' => ['$view->with("title");', ['title' => true]];
        yield 'array form' => ['$view->with(["a" => 1, "b" => 2]);', ['a' => true, 'b' => true]];
        yield 'chained' => ['$view->with("a", 1)->with(["b" => 2]);', ['a' => true, 'b' => true]];
        yield 'conditional, and a closure on another variable' => ['if ($x) { $view->with("a", 1); } $f = function () use ($y) { $y->with("b", 2); };', ['a' => true]];
        yield 'a body that never touches the view' => ['$x = 1;', []];
        yield 'no parameter' => ['$x = 1;', [], ''];
        yield 'a renamed first parameter' => ['$v->with("a", 1);', ['a' => true], '$v'];
        yield 'path read before with' => ['$section = basename(dirname($view->getPath())); $view->with("section", $section);', ['section' => true]];
        yield 'name read inside with' => ['$view->with("a", $view->getName());', ['a' => true]];
        yield 'accessor case-insensitive' => ['$n = $view->NAME();', []];
        yield 'accessor ends the view part of a chain' => ['$view->with("a", 1)->getName();', ['a' => true]];

        yield 'dynamic key' => ['$view->with($key, 1);', null];
        yield 'dynamic array key' => ['$view->with([$key => 1]);', null];
        yield 'array passed as a variable' => ['$view->with($data);', null];
        yield 'compact' => ['$view->with(compact("a"));', null];
        yield 'array with a spread' => ['$view->with([...$data]);', null];
        yield 'list-style array' => ['$view->with([1, 2]);', null];
        yield 'spread arguments' => ['$view->with(...$args);', null];
        yield 'named arguments' => ['$view->with(key: "a", value: 1);', null];
        yield 'with as a first-class callable' => ['$view->with(...);', null];
        yield 'view passed to a method' => ['$this->decorate($view);', null];
        yield 'view passed to the parent' => ['parent::compose($view);', null];
        yield 'view stored in a property' => ['$this->view = $view;', null];
        yield 'view assigned to a local' => ['$copy = $view;', null];
        yield 'result of with assigned' => ['$copy = $view->with("a", 1);', null];
        yield 'result of with passed on' => ['$this->decorate($view->with("a", 1));', null];
        yield 'view captured by a closure' => ['$f = function () use ($view) { $view->with("a", 1); };', null];
        yield 'view used in an arrow function' => ['$f = fn() => $view->with("a", 1);', null];
        yield 'returned view' => ['return $view;', null];
        yield 'returned with call' => ['return $view->with("a", 1);', null];
        yield 'magic property set' => ['$view->title = "x";', null];
        yield 'array access set' => ['$view["title"] = "x";', null];
        yield 'reassigned' => ['$view = other();', null];
        yield 'magic withFoo' => ['$view->withTitle("x");', null];
        yield 'nest' => ['$view->with("a", 1)->nest("b", "c");', null];
        yield 'getData' => ['$d = $view->getData();', null];
        yield 'nullsafe' => ['$view?->with("a", 1);', null];
        yield 'share through a facade' => ['$view->with("a", 1); View::share("b", 1);', null];
        yield 'share through the factory' => ['$view->with("a", 1); $this->factory->share("b", 1);', null];
        yield 'computed method name' => ['$this->{"share"}("b", 1);', null];
        yield 'computed static method name' => ['View::{"share"}("b", 1);', null];
        yield 'variable function' => ['$f = "share"; $f("b", 1);', null];
        yield 'variable variable' => ['$$name = 1;', null];
        yield 'compact reaching the view by name' => ['$vars = compact("view"); $vars["view"]->with("a", 1);', null];
        yield 'get_defined_vars' => ['$this->decorate(get_defined_vars());', null];
        yield 'extract, fully qualified and upper-cased' => ['\EXTRACT($data);', null];
        yield 'func_get_args' => ['func_get_args()[0]->with("a", 1);', null];
        yield 'func_get_arg' => ['func_get_arg(0)->with("a", 1);', null];

        // Accepted residual: a `share()` reached through a callable is not seen.
        yield 'share through call_user_func' => ['call_user_func([View::class, "share"], "b", 1);', []];
    }
}
