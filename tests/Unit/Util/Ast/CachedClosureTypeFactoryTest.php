<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Util\Ast;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Internal\Ast\CachedClosureTypeFactory;
use Psalm\Type\Atomic\TClosure;
use Tests\Psalm\LaravelPlugin\Unit\Util\Ast\Concerns\InitializesPsalmConfigSingleton;

/**
 * Cache-specific behaviour of the memoizing decorator; the build pipeline itself is
 * covered by {@see ClosureTypeFactoryTest}. Observed through file rewrites, since
 * there is no seam for counting calls on the underlying factory.
 */
#[CoversClass(CachedClosureTypeFactory::class)]
final class CachedClosureTypeFactoryTest extends TestCase
{
    use InitializesPsalmConfigSingleton;

    /** @var list<string> */
    private array $tempFiles = [];

    #[\Override]
    protected function setUp(): void
    {
        CachedClosureTypeFactory::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        CachedClosureTypeFactory::reset();
        foreach ($this->tempFiles as $path) {
            if (\is_file($path)) {
                \unlink($path);
            }
        }

        $this->tempFiles = [];
    }

    #[Test]
    public function second_call_with_same_mtime_returns_cached_result(): void
    {
        // First call populates the cache. Subsequent call against the same
        // (realpath, mtime) must return the SAME outcome — verified here by
        // overwriting the file's contents WITHOUT touching its mtime: a
        // re-read would surface the new content; a cache hit returns the old.
        $file = $this->writeTempFile(
            <<<'PHP'
<?php

/**
 * @return non-empty-string
 */
$register(static function (): string {
    return 'first';
});
PHP,
        );

        $closure = $this->loadClosureFromFile($file);
        $first = CachedClosureTypeFactory::fromClosureObject($closure);
        $this->assertInstanceOf(TClosure::class, $first);
        $this->assertSame('non-empty-string', $first->return_type?->getId());

        // Capture the mtime, rewrite contents to something parse-incompatible,
        // then restore the mtime so the cache key still matches. A re-read
        // would either fail to parse (returning null) or return a different
        // shape; a cache hit returns the original.
        $mtime = \filemtime($file);
        \file_put_contents($file, "<?php\n// no closure here\n");
        \touch($file, (int) $mtime);

        $second = CachedClosureTypeFactory::fromClosureObject($closure);
        // Semantic comparison via the Union's getId() — the cache stores
        // the per-file index, not the final TClosure, so the build stage
        // produces fresh objects on every call. What we care about is that
        // the second call returns the same shape, proving the cache
        // shielded us from the rewritten file's content.
        $this->assertInstanceOf(TClosure::class, $second, 'Cache hit must still produce a TClosure');
        $this->assertSame(
            $first->return_type?->getId(),
            $second->return_type?->getId(),
            'Cache hit must return the prior return-type',
        );
    }

    #[Test]
    public function reset_drops_cache_and_re_reads_on_next_call(): void
    {
        // After `reset()`, the next call must re-read the file — observable
        // by rewriting the fixture's docblock and asserting the new types
        // surface. Without `reset()` the first cache entry would mask the
        // change.
        $file = $this->writeTempFile(
            <<<'PHP'
<?php

/**
 * @return non-empty-string
 */
$register(static function () {
    return 'x';
});
PHP,
        );
        $closure = $this->loadClosureFromFile($file);

        $first = CachedClosureTypeFactory::fromClosureObject($closure);
        $this->assertInstanceOf(TClosure::class, $first);
        $this->assertSame('non-empty-string', $first->return_type?->getId());

        CachedClosureTypeFactory::reset();

        // Rewrite the file with a narrower return type; bump mtime so the
        // realpath cache invariant is honoured too.
        \file_put_contents(
            $file,
            <<<'PHP'
<?php

/**
 * @return literal-string
 */
$register(static function () {
    return 'x';
});
PHP,
        );
        \touch($file, \time() + 1);
        \clearstatcache(true, $file);

        $second = CachedClosureTypeFactory::fromClosureObject($this->loadClosureFromFile($file));
        $this->assertInstanceOf(TClosure::class, $second);
        $this->assertSame('literal-string', $second->return_type?->getId());
    }

    private function writeTempFile(string $contents): string
    {
        $base = \tempnam(\sys_get_temp_dir(), 'psalm-laravel-closure-doc-');
        $path = $base . '.php';
        \rename($base, $path);
        \file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Mirrors the helper in {@see ClosureTypeFactoryTest}: requires the
     * fixture in an isolated scope where `$register(...)` captures the
     * Closure-typed argument so we can reflect it.
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
