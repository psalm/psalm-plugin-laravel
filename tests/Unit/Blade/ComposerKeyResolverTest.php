<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Blade\ComposerKeyResolver;
use Psalm\LaravelPlugin\Blade\ContractRegistry;

/**
 * The key set may only ever be too BIG: a key left out is a false positive on the user's call site,
 * a key wrongly added only costs a missed report. Each decline case is one way `$view` can be
 * handed somewhere the walker cannot follow, or one way data reaches it that is not a literal key.
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
        $keys = ComposerKeyResolver::keysIn($this->method($body, $params));

        if ($keys !== null) {
            \ksort($keys);
        }

        $this->assertSame($expected, $keys);
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
        yield 'conditional and nested in a closure' => [
            'if ($x) { $view->with("a", 1); } $f = function () use ($y) { $y->with("b", 2); };',
            ['a' => true],
        ];
        yield 'a body that never touches the view' => ['$x = 1;', []];
        yield 'a renamed first parameter' => ['$v->with("a", 1);', ['a' => true], '$v'];

        yield 'dynamic key' => ['$view->with($key, 1);', null];
        yield 'dynamic array key' => ['$view->with([$key => 1]);', null];
        yield 'array passed as a variable' => ['$view->with($data);', null];
        yield 'array with a spread' => ['$view->with([...$data]);', null];
        yield 'list-style array' => ['$view->with([1, 2]);', null];
        yield 'spread arguments' => ['$view->with(...$args);', null];
        yield 'named key and value' => ['$view->with(key: "a", value: 1);', ['a' => true]];
        yield 'named value before key' => ['$view->with(value: 1, key: "a");', ['a' => true]];
        yield 'named key array' => ['$view->with(key: ["a" => 1, "b" => 2]);', ['a' => true, 'b' => true]];
        yield 'positional key with a named value' => ['$view->with("a", value: 1);', ['a' => true]];
        yield 'unknown argument name' => ['$view->with(name: "a", value: 1);', null];
        yield 'named value without a key' => ['$view->with(value: 1);', null];
        yield 'dynamic named key' => ['$view->with(key: $k, value: 1);', null];
        yield 'returned with call' => ['$view->with("a", 1); return $view->with("b", 2)->with(["c" => 3]);', ['a' => true, 'b' => true, 'c' => true]];
        yield 'returned view' => ['return $view;', null];
        yield 'returned with call inside a branch' => ['if ($x) { return $view->with("a", 1); }', null];
        yield 'returned with call inside a closure' => ['$f = function () { return $view->with("a", 1); };', null];
        yield 'returned with call inside an arrow function' => ['$f = fn() => $view->with("a", 1);', null];
        yield 'with as a first-class callable' => ['$view->with(...);', null];
        yield 'accessor as a first-class callable' => ['$f = $view->getName(...);', null];
        yield 'accessor first-class callable in a chain' => ['$view->with("a", 1)->getName(...);', null];
        yield 'constructor of another class' => ['SharingBase::__construct();', null];
        yield 'constructor through self' => ['self::__construct();', null];
        yield 'constructor through static' => ['static::__construct();', null];
        yield 'constructor through this' => ['$this->__construct();', null];
        yield 'compact' => ['$view->with(compact("a"));', null];
        yield 'extract' => ['extract($data);', null];
        yield 'variable variable' => ['$$name = 1;', null];
        yield 'view passed to a method' => ['$this->decorate($view);', null];
        yield 'view passed to the parent' => ['parent::compose($view);', null];
        yield 'view stored in a property' => ['$this->view = $view;', null];
        yield 'view assigned to a local' => ['$copy = $view;', null];
        yield 'view captured by a closure' => ['$f = function () use ($view) { $view->with("a", 1); };', null];
        yield 'result of with assigned' => ['$copy = $view->with("a", 1);', null];
        yield 'magic property set' => ['$view->title = "x";', null];
        yield 'array access set' => ['$view["title"] = "x";', null];
        yield 'unset' => ['unset($view);', null];
        yield 'reassigned' => ['$view = other();', null];
        yield 'magic withFoo' => ['$view->withTitle("x");', null];
        yield 'nest' => ['$view->with("a", 1)->nest("b", "c");', null];
        yield 'nullsafe' => ['$view?->with("a", 1);', null];
        yield 'read-only name' => ['$view->name();', []];
        yield 'read-only getName feeding with' => ['$view->with("a", $view->getName());', ['a' => true]];
        yield 'read-only getPath case-insensitive' => ['$p = $view->GETPATH();', []];
        yield 'nullsafe accessor' => ['$n = $view?->getName();', []];
        yield 'accessor result used as a string' => ['$n = $view->getName()->trim(); $view->with("a", 1);', ['a' => true]];
        yield 'getData' => ['$d = $view->getData();', null];
        yield 'getFactory' => ['$view->getFactory()->share("a", 1);', null];
        yield 'accessor with a computed name' => ['$view->{"getName"}();', null];
        yield 'read-only property' => ['$n = $view->name;', null];
        yield 'accessor on a with chain' => ['$view->with("a", 1)->getName();', ['a' => true]];
        yield 'share through a facade' => ['$view->with("a", 1); View::share("b", 1);', null];
        yield 'share through the factory' => ['$view->with("a", 1); $this->factory->share("b", 1);', null];
        yield 'parent constructor delegation' => ['parent::__construct();', null];
        yield 'share with a computed static method name' => ['View::{"share"}("b", 1);', null];
        yield 'share with a computed method name' => ['$this->{"share"}("b", 1);', null];
        yield 'share through a variable function' => ['$f = "share"; $f("b", 1);', null];
        yield 'call_user_func' => ['call_user_func([View::class, "share"], "b", 1);', null];
        yield 'call_user_func_array' => ['call_user_func_array([View::class, "share"], ["b", 1]);', null];
        yield 'func_get_args under an imported alias' => ['args();', null];
        yield 'debug_backtrace' => ['debug_backtrace(0, 1);', null];
        yield 'debug_print_backtrace' => ['debug_print_backtrace();', null];
        yield 'default value that constructs an object' => ['$view->with("a", 1);', null, '$view, $dep = new Dep()'];
        yield 'default value that is a constant expression' => ['$view->with("a", 1);', ['a' => true], '$view, $n = 1 + 2, $m = self::LIMIT'];
        yield 'include shares the local scope' => ['require __DIR__ . "/data.php"; $view->with("a", 1);', null];
        yield 'include_once shares the local scope' => ['include_once "data.php";', null];
        yield 'eval shares the local scope' => ['eval($code);', null];
        yield 'func_get_args without a parameter' => ['func_get_args();', null, ''];
        yield 'by-reference parameter' => ['$view->with("a", 1);', null, '&$view'];
        yield 'variadic parameter' => ['$view[0]->with("a", 1);', null, '...$view'];
        yield 'parameter with a default' => ['$view->with("a", 1);', ['a' => true], '$view = null'];
    }

    #[Test]
    public function a_method_without_a_parameter_that_never_reads_arguments_supplies_nothing(): void
    {
        $this->assertSame([], ComposerKeyResolver::keysIn($this->method('$x = 1;', '')));
    }

    /** A view that stops being provable must lose the key set an earlier run recorded. */
    #[Test]
    public function an_unreadable_source_replaces_a_key_set_recorded_earlier(): void
    {
        ContractRegistry::reset();
        ContractRegistry::registerComposedKeys('page', ['title' => true]);
        ContractRegistry::registerComposerSources('page', null);

        // An unreadable source never reaches the codebase, which is final and heavy to build.
        ComposerKeyResolver::resolve((new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor());

        $this->assertNull(ContractRegistry::composedKeys('page'));
        ContractRegistry::reset();
    }

    #[Test]
    public function an_abstract_method_is_unknown(): void
    {
        $this->assertNull(ComposerKeyResolver::keysIn($this->method('', '$view', true)));
    }

    private function method(string $body, string $params = '$view', bool $abstract = false): \PhpParser\Node\Stmt\ClassMethod
    {
        $code = $abstract
            ? "<?php abstract class C { abstract function compose({$params}); }"
            : "<?php class C { function compose({$params}) { {$body} } }";

        $code = \str_replace('<?php ', '<?php use function func_get_args as args; ', $code);

        $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [];
        // Psalm resolves names the same way before any handler sees them.
        (new NodeTraverser(new NameResolver(null, ['replaceNodes' => false])))->traverse($stmts);
        $stmts = \array_values(\array_filter($stmts, static fn(Node $node): bool => $node instanceof Class_));
        $class = $stmts[0];

        return $class->getMethods()[0];
    }
}
