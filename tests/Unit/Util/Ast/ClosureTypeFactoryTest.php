<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Util\Ast;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Config;
use Psalm\Internal\EventDispatcher;
use Psalm\LaravelPlugin\Util\Ast\ClosureDocblockIndexVisitor;
use Psalm\LaravelPlugin\Util\Ast\ClosureTypeFactory;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Union;

/**
 * Unit coverage for the stateless AST-scan path that recovers `@param` /
 * `@return` from closure source files Psalm did not scan ahead of time
 * (issue #991).
 *
 * The type test (`MacroAstScanVendorClosureTest.phpt`) exercises the same
 * pipeline end-to-end through the plugin's macro pseudo-method synthesis;
 * this test targets {@see ClosureTypeFactory} in isolation so failures
 * point at the factory layer rather than at the broader registry / handler
 * stack. The memoizing wrapper
 * {@see \Psalm\LaravelPlugin\Util\Ast\CachedClosureTypeFactory} has its own
 * dedicated test ({@see CachedClosureTypeFactoryTest}); we deliberately do
 * not exercise it here so failures in one layer cannot mask failures in
 * the other.
 *
 * Fixtures are written to disk-temp files because the factory reads closure
 * source via `realpath()` + `file_get_contents()`.
 */
#[CoversClass(ClosureTypeFactory::class)]
#[CoversClass(ClosureDocblockIndexVisitor::class)]
final class ClosureTypeFactoryTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    public static function setUpBeforeClass(): void
    {
        // PR #994 literal-string body inference constructs `TLiteralString`
        // directly, which reads `max_string_length` from Psalm's singleton
        // {@see Config}. Production callers always have a `Config` (the
        // plugin runs inside Psalm), but the unit-test harness does not
        // bootstrap one. Populate just enough to make the literal-string
        // path succeed — heavier alternatives (loading `tests/Type/psalm.xml`
        // through `Config::loadFromXMLFile`) pull in schema validation and
        // composer-classmap warmups that aren't relevant here.
        $rc = new \ReflectionClass(Config::class);
        $instance = $rc->newInstanceWithoutConstructor();
        $rc->getProperty('instance')->setValue(null, $instance);
        $rc->getProperty('eventDispatcher')->setValue($instance, new EventDispatcher());
    }

    public static function tearDownAfterClass(): void
    {
        // Restore the "no Config initialized" precondition other unit tests
        // (notably `NoEnvOutsideConfigHandlerTest`) rely on. Without this
        // teardown the singleton planted in setUpBeforeClass leaks across
        // test classes within one PHPUnit run, masking real "config not yet
        // bootstrapped" guard tests.
        (new \ReflectionClass(Config::class))->getProperty('instance')->setValue(null, null);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (\is_file($path)) {
                \unlink($path);
            }
        }

        $this->tempFiles = [];
    }

    #[Test]
    public function builds_tclosure_from_wrapping_expression_docblock(): void
    {
        // Mirrors the Inertia call site: docblock attaches to the wrapping
        // `Stmt\Expression`, not to the closure itself. This is exactly the
        // case issue #991 set out to fix — storage-only recovery would miss
        // it.
        $file = $this->writeTempFile(
            <<<'PHP'
<?php
declare(strict_types=1);

namespace Tests\Closure\Vendor;

/**
 * @param positive-int $count
 * @return non-empty-string
 */
$register('astStmtExpression', static function (int $count) {
    return \str_repeat('z', $count);
});
PHP,
        );

        $closure = $this->loadClosureFromFile($file);
        $result = ClosureTypeFactory::fromClosureObject($closure);

        $this->assertInstanceOf(TClosure::class, $result);
        $this->assertCount(1, $result->params ?? []);
        $countParam = ($result->params ?? [])[0];
        $this->assertSame('count', $countParam->name);
        $this->assertInstanceOf(Union::class, $countParam->type);
        $this->assertSame('int<1, max>', $countParam->type->getId());
        $this->assertInstanceOf(Union::class, $result->return_type);
        $this->assertSame('non-empty-string', $result->return_type->getId());
    }

    // The namespace + `use`-alias resolution path is exercised end-to-end by
    // the companion type test (`MacroAstScanVendorClosureTest.phpt`'s
    // `astDocblockGenericTest` assertion). Re-covering it here would require
    // bootstrapping `ProjectAnalyzer::$instance` (touched indirectly by
    // `TypeParser::parseTokens()` when resolving generic class references),
    // which the unit-test harness deliberately avoids — see the
    // `is_nullable_via_reflection` note in MacroRegistryTest for the same
    // trade-off.

    #[Test]
    public function returns_null_when_two_closures_share_a_start_line(): void
    {
        // Correctness guard: when reflection points at a line with multiple
        // closures (rare but possible — e.g. `[fn() => 1, fn() => 2]`), the
        // factory bails rather than pick the wrong one. Mirrors the same
        // ambiguity policy in `MacroRegistry::recoverClosureStorage()`.
        $file = $this->writeTempFile(
            <<<'PHP'
<?php

$register(static fn (int $a): int => $a, static fn (int $b): int => $b);
PHP,
        );

        $closure = $this->loadClosureFromFile($file);

        $this->assertNull(ClosureTypeFactory::fromClosureObject($closure));
    }

    #[Test]
    public function returns_null_when_closure_has_no_docblock(): void
    {
        $file = $this->writeTempFile(
            <<<'PHP'
<?php

$register(static function (int $x): int { return $x; });
PHP,
        );

        $closure = $this->loadClosureFromFile($file);

        $this->assertNull(ClosureTypeFactory::fromClosureObject($closure));
    }

    /**
     * PR #994 body-flow inference fixtures. Each test installs a closure that
     * has NO docblock and NO native return type, so the factory has nothing to
     * fall back on except the body-flow inference path. The asserted `getId()`
     * is what callers see as the closure's recovered return type.
     */
    #[Test]
    public function body_infer_literal_string(): void
    {
        $result = $this->buildInferredFromBody("static fn () => 'hello'");
        $this->assertSame("'hello'", $result->return_type?->getId());
    }

    #[Test]
    public function body_infer_literal_int(): void
    {
        $result = $this->buildInferredFromBody('static fn () => 42');
        $this->assertSame('42', $result->return_type?->getId());
    }

    #[Test]
    public function body_infer_literal_float(): void
    {
        $result = $this->buildInferredFromBody('static fn () => 1.5');
        // `getId(exact: true)` on TLiteralFloat renders as `float(<value>)` —
        // distinct from TLiteralInt and TLiteralString, which render as bare
        // numerals and quoted strings respectively. Lock the exact rendering
        // so a Psalm-side change to literal-float formatting is caught.
        $this->assertSame('float(1.5)', $result->return_type?->getId());
    }

    #[Test]
    public function body_infer_const_true(): void
    {
        $result = $this->buildInferredFromBody('static fn () => true');
        $this->assertSame('true', $result->return_type?->getId());
    }

    #[Test]
    public function body_infer_const_false(): void
    {
        $result = $this->buildInferredFromBody('static fn () => false');
        $this->assertSame('false', $result->return_type?->getId());
    }

    #[Test]
    public function body_infer_const_null(): void
    {
        $result = $this->buildInferredFromBody('static fn () => null');
        $this->assertSame('null', $result->return_type?->getId());
    }

    #[Test]
    public function body_infer_concat_literal_strings(): void
    {
        $result = $this->buildInferredFromBody("static fn () => 'a' . 'b'");
        $this->assertSame("'ab'", $result->return_type?->getId());
    }

    #[Test]
    public function body_infer_arithmetic_returns_int_or_float(): void
    {
        // The spec deliberately widens arithmetic to `int|float` even when
        // both operands are int literals: the same operator can produce a
        // float at runtime (1/2, intdiv overflow on 32-bit, etc.), and
        // modelling each case exactly would explode the rule table.
        $result = $this->buildInferredFromBody('static fn () => 1 + 2');
        $this->assertSame('float|int', $result->return_type?->getId());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function arithmeticOperators(): iterable
    {
        // All six arithmetic operators share a single branch in
        // {@see ClosureTypeFactory::inferExpression()}. If a future refactor
        // splits the implementation per-operator (e.g. modeling `Mod` as
        // int-only), the data provider catches the regression.
        yield 'plus' => ['1 + 2'];
        yield 'minus' => ['5 - 2'];
        yield 'mul' => ['3 * 4'];
        yield 'div' => ['8 / 2'];
        yield 'mod' => ['7 % 3'];
        yield 'pow' => ['2 ** 8'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('arithmeticOperators')]
    public function body_infer_arithmetic_widens_uniformly_for_every_operator(string $expression): void
    {
        $result = $this->buildInferredFromBody("static fn () => {$expression}");
        $this->assertSame('float|int', $result->return_type?->getId());
    }

    #[Test]
    public function body_infer_chained_concat_folds_to_literal(): void
    {
        // Left-associative parsing makes `'a' . 'b' . 'c'` a Concat of
        // (Concat('a','b'), 'c'). The inner Concat must recurse through
        // `inferExpression()` and surface its folded literal so the outer
        // Concat sees a single-string-literal operand.
        $result = $this->buildInferredFromBody("static fn () => 'a' . 'b' . 'c'");
        $this->assertSame("'abc'", $result->return_type?->getId());
    }

    #[Test]
    public function body_infer_bails_on_unary_minus(): void
    {
        // PhpParser models `-1` as `UnaryMinus(Int_(1))` — not a literal
        // scalar. The spec's rule table covers `Scalar\Int_` but not
        // `UnaryMinus`, so signed-literal closures bail. Lock that in so a
        // later "obvious" widening doesn't silently start producing `-1`
        // (or worse, `1`) for sloppy callers that wrote `fn() => -1`.
        $file = $this->writeTempFile(
            <<<'PHP'
<?php
$register(static fn () => -1);
PHP,
        );
        $closure = $this->loadClosureFromFile($file);

        $this->assertNull(ClosureTypeFactory::fromClosureObject($closure));
    }

    #[Test]
    public function body_infer_accepts_throw_only_terminator(): void
    {
        // `bodyAlwaysTerminates()` considers a `Stmt\Expression(Expr\Throw_)`
        // tail as terminating, but there is no `return` to feed the inference
        // engine — so we still bail to `null`, just on a different code path
        // than `bodyAlwaysTerminates() === false`. Lock both branches in.
        $file = $this->writeTempFile(
            <<<'PHP'
<?php
$register(static function () { throw new \RuntimeException(); });
PHP,
        );
        $closure = $this->loadClosureFromFile($file);

        $this->assertNull(ClosureTypeFactory::fromClosureObject($closure));
    }

    #[Test]
    public function body_infer_bails_when_terminator_is_try_catch(): void
    {
        // `try { return 1; } catch { return 2; }` terminates in both arms,
        // but `bodyAlwaysTerminates()` is intentionally conservative: it
        // only accepts a plain `Return_` or `Throw_` as the last top-level
        // statement. A `Stmt\TryCatch` fails the check, so inference bails.
        // Documenting the limit here ensures a future relaxation (e.g.
        // teaching `bodyAlwaysTerminates()` to peek inside `try/catch`)
        // is a deliberate change, not a silent drift.
        $file = $this->writeTempFile(
            <<<'PHP'
<?php
$register(static function () {
    try { return 1; } catch (\Throwable) { return 2; }
});
PHP,
        );
        $closure = $this->loadClosureFromFile($file);

        $this->assertNull(ClosureTypeFactory::fromClosureObject($closure));
    }

    #[Test]
    public function body_infer_nested_shaped_array(): void
    {
        // `inferArray()` recurses through `inferExpression()`, so nested
        // arrays should compose into a nested shaped array. If someone
        // "simplifies" the recursion to only accept literal scalars at the
        // leaves, the per-rule tests still pass but this composition test
        // fails — that's the regression-protection value.
        $result = $this->buildInferredFromBody("static fn () => [[1, 2], 3]");
        $this->assertSame('list{list{1, 2}, 3}', $result->return_type?->getId());
    }

    #[Test]
    public function body_infer_mixed_key_array_drops_list_marker(): void
    {
        // Any explicit key flips `$isList` to false. Verifies the auto-index
        // path interacts correctly with explicit keys in the same array.
        $result = $this->buildInferredFromBody("static fn () => [1, 'k' => 2]");
        $this->assertSame("array{0: 1, k: 2}", $result->return_type?->getId());
    }

    #[Test]
    public function body_infer_shaped_list_array(): void
    {
        $result = $this->buildInferredFromBody("static fn () => [1, 'x']");
        $this->assertSame("list{1, 'x'}", $result->return_type?->getId());
    }

    #[Test]
    public function body_infer_shaped_keyed_array(): void
    {
        $result = $this->buildInferredFromBody("static fn () => ['k' => 1]");
        $this->assertSame("array{k: 1}", $result->return_type?->getId());
    }

    #[Test]
    public function body_infer_multi_return_unions_branches(): void
    {
        // `if/else`: both branches yield literal returns; the result should be
        // their union. Locks in `combineUnionTypeArray()` wiring.
        $closureSource = <<<'PHP'
static function () {
    if (\random_int(0, 1) === 0) {
        return 1;
    }
    return 'x';
}
PHP;
        $result = $this->buildInferredFromBody($closureSource);
        // Union::getId() sorts atomics alphabetically — apostrophe (39) sorts
        // before digit (49), so `'x'` precedes `1` in the rendered output.
        $this->assertSame("'x'|1", $result->return_type?->getId());
    }

    #[Test]
    public function body_infer_bails_on_variable_return(): void
    {
        // Variable returns are out of scope (the spec explicitly excludes
        // variable type-flow). Without docblock and without a native return,
        // body inference bails and the factory returns `null`.
        $file = $this->writeTempFile(
            <<<'PHP'
<?php
$register(static function () { $x = 1; return $x; });
PHP,
        );
        $closure = $this->loadClosureFromFile($file);

        $this->assertNull(ClosureTypeFactory::fromClosureObject($closure));
    }

    #[Test]
    public function body_infer_bails_on_unhandled_node(): void
    {
        // Method call inside the return is outside the rule table — the
        // factory bails the entire inference rather than guess.
        $file = $this->writeTempFile(
            <<<'PHP'
<?php
$register(static fn () => (new \stdClass())->foo);
PHP,
        );
        $closure = $this->loadClosureFromFile($file);

        $this->assertNull(ClosureTypeFactory::fromClosureObject($closure));
    }

    #[Test]
    public function body_infer_does_not_run_when_native_return_present(): void
    {
        // Reflection sees `: int`, so the existing precedence (docblock then
        // native) wins. Body inference is suppressed, and since the closure
        // also has no docblock the factory falls back to `null` — caller
        // keeps its reflection-only pseudo-method path.
        $file = $this->writeTempFile(
            <<<'PHP'
<?php
$register(static function (): int { return 7; });
PHP,
        );
        $closure = $this->loadClosureFromFile($file);

        $this->assertNull(ClosureTypeFactory::fromClosureObject($closure));
    }

    #[Test]
    public function body_infer_bails_on_implicit_null_fallthrough(): void
    {
        // Conservatively bail when the body has a code path that reaches the
        // closing brace without an explicit `return` — PHP returns `null` in
        // that case, and inferring just the explicit return value would
        // silently disagree with runtime.
        $file = $this->writeTempFile(
            <<<'PHP'
<?php
$register(static function () {
    if (\random_int(0, 1) === 0) {
        return 1;
    }
});
PHP,
        );
        $closure = $this->loadClosureFromFile($file);

        $this->assertNull(ClosureTypeFactory::fromClosureObject($closure));
    }

    #[Test]
    public function body_infer_skips_nested_closure_returns(): void
    {
        // The outer closure's only `return` yields a literal; the inner
        // closure also returns something, but its return belongs to the inner
        // function and must NOT contaminate the outer inference.
        $file = $this->writeTempFile(
            <<<'PHP'
<?php
$register(static function () {
    $cb = static function () { return 'inner'; };
    return 'outer';
});
PHP,
        );
        $closure = $this->loadClosureFromFile($file);
        $result = ClosureTypeFactory::fromClosureObject($closure);

        $this->assertInstanceOf(TClosure::class, $result);
        $this->assertSame("'outer'", $result->return_type?->getId());
    }

    /**
     * Helper: write a fixture file whose only closure is `$closureSource`,
     * load it, and assert the recovered TClosure exists. Body-only inference
     * tests share this scaffolding because they all want a fresh single-closure
     * file with no docblock and no native return type.
     */
    private function buildInferredFromBody(string $closureSource): TClosure
    {
        $file = $this->writeTempFile("<?php\n\$register({$closureSource});\n");
        $closure = $this->loadClosureFromFile($file);
        $result = ClosureTypeFactory::fromClosureObject($closure);

        $this->assertInstanceOf(TClosure::class, $result, 'Body inference should have produced a TClosure');

        return $result;
    }

    #[Test]
    public function ignores_outer_function_docblock_for_nested_closure(): void
    {
        // Doc-attachment rule (strict): walk up to the nearest *Stmt*, accept
        // its docblock only when it is a `Stmt\Expression`. A
        // `@return non-empty-string` on a surrounding `function makeCallback()`
        // declaration must NOT leak onto a closure returned from inside that
        // function — its `@return` describes `makeCallback()`'s own return,
        // not the inner closure.
        //
        // Unique namespace per test run keeps repeated requires from
        // triggering PHP's "Cannot redeclare function" error.
        $namespace = 'Tests\\Closure\\OuterDoc\\Ns' . \bin2hex(\random_bytes(4));
        $file = $this->writeTempFile(
            <<<PHP
<?php
declare(strict_types=1);

namespace {$namespace};

/**
 * @return non-empty-string
 */
function makeCallback(): \\Closure {
    return static function (int \$n) {
        return \$n * 2;
    };
}
PHP,
        );
        require_once $file;

        $functionName = $namespace . '\\makeCallback';
        $closure = $functionName();
        $this->assertInstanceOf(\Closure::class, $closure);

        $this->assertNull(ClosureTypeFactory::fromClosureObject($closure));
    }

    private function writeTempFile(string $contents): string
    {
        $base = \tempnam(\sys_get_temp_dir(), 'psalm-laravel-closure-doc-');
        $path = $base . '.php';
        // Move the tempnam-placeholder file so the `.php` extension applies.
        \rename($base, $path);
        \file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Each fixture file ends with `$register(...)` calls whose last argument
     * is the closure we want to inspect. The fake `$register` captures every
     * Closure-typed argument; the last capture is returned.
     */
    private function loadClosureFromFile(string $path): \Closure
    {
        $closure = (static function (string $path): ?\Closure {
            $captured = null;
            $register = static function (...$args) use (&$captured): \Closure {
                foreach ($args as $arg) {
                    if ($arg instanceof \Closure) {
                        $captured = $arg;
                    }
                }

                return $captured ?? static fn(): null => null;
            };
            require $path;

            return $captured;
        })($path);

        $this->assertInstanceOf(\Closure::class, $closure, 'Fixture did not register a closure');

        return $closure;
    }
}
