<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Util\Ast;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Internal\Ast\ClosureDocblockIndexVisitor;
use Psalm\LaravelPlugin\Internal\Ast\ClosureTypeFactory;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Union;
use Tests\Psalm\LaravelPlugin\Unit\Util\Ast\Concerns\InitializesPsalmConfigSingleton;

/**
 * Unit coverage for the stateless AST-scan path that recovers `@param` /
 * `@return` from closure source files Psalm did not scan ahead of time
 * (issue #991). Fixtures are written to temp files because the factory reads
 * closure source via `realpath()` + `file_get_contents()`.
 *
 * The memoizing wrapper has its own test ({@see CachedClosureTypeFactoryTest});
 * the namespace + `use`-alias resolution path is covered end-to-end by
 * `MacroAstScanVendorClosureTest.phpt` since it needs `ProjectAnalyzer::$instance`.
 */
#[CoversClass(ClosureTypeFactory::class)]
#[CoversClass(ClosureDocblockIndexVisitor::class)]
final class ClosureTypeFactoryTest extends TestCase
{
    use InitializesPsalmConfigSingleton;

    /** @var list<string> */
    private array $tempFiles = [];

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
        // The docblock attaches to the wrapping `Stmt\Expression`, not to the closure itself
        // (the Inertia call site); storage-only recovery would miss it.
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

    #[Test]
    public function returns_null_when_two_closures_share_a_start_line(): void
    {
        // Reflection only yields a line, so an ambiguous line must bail rather than pick the wrong closure.
        $this->assertBailsForBody(<<<'PHP'
<?php

$register(static fn (int $a): int => $a, static fn (int $b): int => $b);
PHP);
    }

    #[Test]
    public function ignores_outer_function_docblock_for_nested_closure(): void
    {
        // A docblock is accepted only on a `Stmt\Expression`: the `@return` on a surrounding
        // `function makeCallback()` describes that function, not the closure returned from it.
        // The unique namespace avoids "Cannot redeclare function" on repeated requires.
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

    // --- Body-flow inference (PR #994): closures with no docblock and no native return type ---

    /** @return iterable<string, array{string, string}> */
    public static function provideInferredBodies(): iterable
    {
        yield 'literal string' => ["static fn () => 'hello'", "'hello'"];
        // TLiteralFloat renders as `float(<value>)`, unlike int/string literals.
        yield 'literal float' => ['static fn () => 1.5', 'float(1.5)'];
        yield 'const true' => ['static fn () => true', 'true'];
        yield 'const null' => ['static fn () => null', 'null'];
        // The inner Concat must recurse through inferExpression() so the outer sees one literal.
        yield 'chained concat folds to a literal' => ["static fn () => 'a' . 'b' . 'c'", "'abc'"];
        // Arithmetic is deliberately widened: the same operator can yield a float at runtime.
        yield 'arithmetic' => ['static fn () => 1 + 2', 'float|int'];
        yield 'arithmetic with a float operand' => ['static fn () => 1 + 2.5', 'float|int'];
        yield 'nested shaped array' => ['static fn () => [[1, 2], 3]', 'list{list{1, 2}, 3}'];
        yield 'explicit key drops the list marker' => ["static fn () => [1, 'k' => 2]", 'array{0: 1, k: 2}'];
        // Union::getId() sorts atomics alphabetically: the apostrophe sorts before the digit.
        yield 'multiple returns union their branches' => [
            <<<'PHP'
static function () {
    if (\random_int(0, 1) === 0) {
        return 1;
    }
    return 'x';
}
PHP,
            "'x'|1",
        ];
        // The inner closure's return belongs to the inner function and must not contaminate the outer.
        yield 'nested closure returns are skipped' => [
            <<<'PHP'
static function () {
    $cb = static function () { return 'inner'; };
    return 'outer';
}
PHP,
            "'outer'",
        ];
    }

    #[Test]
    #[DataProvider('provideInferredBodies')]
    public function body_inference(string $closureSource, string $expectedReturnType): void
    {
        $file = $this->writeTempFile("<?php\n\$register({$closureSource});\n");
        $result = ClosureTypeFactory::fromClosureObject($this->loadClosureFromFile($file));

        $this->assertInstanceOf(TClosure::class, $result, 'Body inference should have produced a TClosure');
        $this->assertSame($expectedReturnType, $result->return_type?->getId());
    }

    /**
     * Each bail keeps the caller on its reflection-only pseudo-method path.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideBailingBodies(): iterable
    {
        // Without the numeric gate `1 + 'x'` would widen to `int|float`, but runtime is a TypeError.
        yield 'arithmetic with a non-numeric operand' => ["static fn () => 1 + 'x'"];
        // Folding `'a' . 1` would produce types Psalm renders as numeric-string or int.
        yield 'concat with a non-string operand' => ["static fn () => 'a' . 1"];
        // PhpParser models `-1` as UnaryMinus(Int_(1)), which the rule table does not cover.
        yield 'unary minus' => ['static fn () => -1'];
        yield 'throw-only body' => ['static function () { throw new \RuntimeException(); }'];
        // bodyAlwaysTerminates() only accepts a plain Return_/Throw_ as the last top-level statement.
        yield 'try/catch terminator' => ['static function () { try { return 1; } catch (\Throwable) { return 2; } }'];
        yield 'variable return' => ['static function () { $x = 1; return $x; }'];
        // A native return type suppresses body inference, and with no docblock the factory has nothing left.
        yield 'native return type present' => ['static function (): int { return 7; }'];
        // PHP returns null when control reaches the closing brace, so inferring only the explicit return would disagree.
        yield 'implicit null fallthrough' => ['static function () { if (\random_int(0, 1) === 0) { return 1; } }'];
    }

    #[Test]
    #[DataProvider('provideBailingBodies')]
    public function body_inference_bails(string $closureSource): void
    {
        $this->assertBailsForBody("<?php\n\$register({$closureSource});\n");
    }

    private function assertBailsForBody(string $fixturePhp): void
    {
        $file = $this->writeTempFile($fixturePhp);
        $closure = $this->loadClosureFromFile($file);

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
