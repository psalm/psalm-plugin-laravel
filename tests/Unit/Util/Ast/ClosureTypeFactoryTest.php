<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Util\Ast;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
