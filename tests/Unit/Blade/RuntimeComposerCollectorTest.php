<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Internal\PhpVisitor\SimpleNameResolver;
use Psalm\LaravelPlugin\Blade\RuntimeComposerCollector;
use Tests\Psalm\LaravelPlugin\Unit\Blade\Fixtures\RuntimeComposer\ConcreteProvider;

/**
 * A registration is recognized by its receiver, never by the method name alone, and a view argument
 * the collector cannot name makes the whole result unknown (null).
 */
#[CoversClass(RuntimeComposerCollector::class)]
final class RuntimeComposerCollectorTest extends TestCase
{
    private const HEADER = "<?php\nnamespace App\\Http;\nuse Illuminate\\Support\\Facades\\View;\nuse Illuminate\\Contracts\\View\\Factory;\n";

    /** @param list<string>|null $expected */
    #[Test]
    #[DataProvider('sources')]
    public function it_collects_the_view_patterns_a_runtime_registration_names(string $code, ?array $expected, bool $rootViewIsFacade = true): void
    {
        $this->assertSame($expected, RuntimeComposerCollector::patternsIn(
            $this->parse($code),
            ['App\\Providers\\ViewServiceProvider', ConcreteProvider::class],
            $rootViewIsFacade,
        ));
    }

    /** @return iterable<string, array{0: string, 1: list<string>|null, 2?: bool}> */
    public static function sources(): iterable
    {
        yield 'facade, literal and array' => ['View::composer("a", $cb); View::creator(["b", "admin/c"], $cb);', ['a', 'b', 'admin.c']];
        yield 'facade alias and FQCN' => ['\View::composer("a", $cb); \Illuminate\Support\Facades\View::composer("b", $cb);', ['a', 'b']];
        yield 'namespaced view and wildcard' => ['view()->composer(["pkg::x/y", "z.*"], $cb);', ['pkg::x.y', 'z.*']];
        yield 'container forms' => ['app("view")->composer("a", $cb); resolve(Factory::class)->creator("b", $cb);', ['a', 'b']];
        yield 'composers map' => ['View::composers(["A" => "a", "B" => ["b", "c"]]);', ['a', 'b', 'c']];
        yield 'typed parameter, seen from an arrow function' => [
            'function f(?Factory $f) { $g = fn() => $f->composer("a", $cb); }',
            ['a'],
        ];
        yield 'closure use and local assignment' => [
            'function f(Factory $f) { $g = function () use ($f) { $f->composer("a", $cb); }; $h = view(); $h->creator("b", $cb); }',
            ['a', 'b'],
        ];
        yield 'top-level assignment, as in a routes file' => ['$f = view(); $f->composer("a", $cb);', ['a']];
        yield 'typed and promoted properties' => [
            'class M { private \Illuminate\View\Factory $a; public function __construct(private Factory $b) {} '
            . 'public function h() { $this->a->composer("a", $cb); $this->b->composer("b", $cb); } }',
            ['a', 'b'],
        ];

        // Not a view factory, so not a registration.
        yield 'userland receiver' => ['$registry->composer("a", $cb); Other::composer("b", $cb); view("x")->composer("c", $cb);', []];
        yield 'untyped and reassigned variable' => ['function f($f, Factory $g) { $f->composer("a", $cb); $g = new X(); $g->composer("b", $cb); }', []];
        yield 'parameter not visible to a closure without use' => ['function f(Factory $f) { $g = function () { $f->composer("a", $cb); }; }', []];
        yield 'another method name' => ['View::share("a", 1); view()->make("a");', []];

        // A boot-time provider's own registration was read at boot; a closure there runs later.
        yield 'loaded provider: direct call skipped, closure counted' => [
            'namespace App\Providers; use Illuminate\Support\Facades\View; class ViewServiceProvider { public function boot() { '
            . 'View::composer("a", $cb); $this->app->booted(fn() => View::composer("b", $cb)); } }',
            ['b'],
        ];
        yield 'provider the app did not load' => [
            'namespace App\Providers; use Illuminate\Support\Facades\View; class Other { public function boot() { View::composer("a", $cb); } }',
            ['a'],
        ];

        yield 'nullsafe call on a typed factory' => ['function f(?Factory $f) { $f?->composer("a", $cb); }', ['a']];
        yield 'named arguments in any order' => ['View::composer(callback: "C", views: "a"); View::composers(composers: ["C" => "b"]);', ['a', 'b']];
        yield 'namespace-relative type resolves against the namespace, not imports' => ['function f(namespace\\Factory $f) { $f->composer("a", $cb); }', []];
        yield 'root View alias that is not the facade' => ['\\View::composer("a", $cb);', [], false];
        yield 'imported view() function' => ['use function Foo\\view; view()->composer("a", $cb);', []];
        yield 'container key compared exactly' => ['app("VIEW")->composer("a", $cb); app("\\\\view")->composer("b", $cb);', []];
        yield 'first-class callable creation registers nothing' => ['$register = View::composer(...);', []];
        yield 'no views argument registers nothing' => ['View::composer(callback: "C");', []];
        yield 'inner parameter shadows an outer factory' => [
            'function f(Factory $v) { $a = fn(Other $v) => $v->composer("a", $cb); $b = function ($v) { $v->composer("b", $cb); }; $v->composer("c", $cb); }',
            ['c'],
        ];
        yield 'provider base class and trait of a loaded provider' => [
            'namespace Tests\\Psalm\\LaravelPlugin\\Unit\\Blade\\Fixtures\\RuntimeComposer; use Illuminate\\Support\\Facades\\View; '
            . 'abstract class BaseProvider { public function boot() { View::composer("a", $cb); } } '
            . 'trait RegistersComposers { public function register() { View::creator("b", $cb); } }',
            [],
        ];

        // Views that cannot be named: every contracted view is unknown.
        yield 'variable view name' => ['View::composer($name, $cb);', null];
        yield 'array with a variable item' => ['View::composer(["a", $name], $cb);', null];
        yield 'spread arguments' => ['View::composer(...$args);', null];
        yield 'composers map with a variable value' => ['View::composers(["A" => $views]);', null];
        yield 'composers map from a variable' => ['View::composers($map);', null];
    }

    /** @return list<Stmt> */
    private function parse(string $code): array
    {
        if (!\str_starts_with($code, 'namespace')) {
            $code = self::HEADER . $code;
        } else {
            $code = "<?php\n" . $code;
        }

        $stmts = (new ParserFactory())->createForHostVersion()->parse($code) ?? [];
        (new NodeTraverser(new SimpleNameResolver(new Collecting())))->traverse($stmts);

        return \array_values($stmts);
    }
}
