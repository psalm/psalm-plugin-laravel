<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util\Ast;

use PhpParser\Comment\Doc;
use Psalm\Aliases;
use Psalm\Type\Atomic\TClosure;

/**
 * Memoizing decorator around {@see ClosureTypeFactory}.
 *
 * The expensive part of the factory pipeline is the per-file AST parse +
 * visitor walk that builds the per-start-line index of `Closure` /
 * `ArrowFunction` nodes. The downstream parameter / return type construction
 * is cheap by comparison. This wrapper caches only the expensive bit, keyed
 * by `(realpath, mtime)`, so a vendor file registering N macros parses once
 * per analysis run.
 *
 * Composition is intentional: {@see ClosureTypeFactory} owns "build a
 * `TClosure` from a closure"; this class owns "memoize the file index".
 * Adding TTL / LRU / cross-run persistence later means another wrapper, not
 * a rewrite of the factory.
 *
 * Lifetime:
 * - Per-(file, mtime) results are cached in-memory for the current analysis
 *   run only. Psalm's own `StatementsProvider` explicitly bypasses its
 *   on-disk parser cache for vendor paths (`StatementsProvider::getStatementsForFile()`
 *   line 103 in vendor/vimeo/psalm), so cross-run persistence would require
 *   its own infrastructure and is out of scope here.
 * - Negative entries (parse error, unreadable file, no closure-bearing nodes)
 *   are cached too, so a single broken vendor file doesn't trigger N parse
 *   retries within one run.
 * - {@see self::reset()} drops the cache.
 *   {@see \Psalm\LaravelPlugin\Providers\MacroRegistry::reset()} chains into
 *   it for test isolation.
 *
 * @internal
 */
final class CachedClosureTypeFactory
{
    /**
     * @var array<string, array{mtime: int, entries: array<int, list<array{0: ?Doc, 1: Aliases}>>|null}>
     */
    private static array $cache = [];

    /**
     * Memoized counterpart of {@see ClosureTypeFactory::fromClosureObject()}.
     *
     * Delegates to the factory via `buildWithIndexer()` so the realpath →
     * line-lookup → type-build pipeline lives in one place; this class only
     * substitutes a memoized file-indexer.
     */
    public static function fromClosureObject(\Closure $closure): ?TClosure
    {
        return ClosureTypeFactory::buildWithIndexer($closure, self::indexFile(...));
    }

    /**
     * Reset the cache. Tests rely on this for isolation between runs;
     * production code calls it indirectly via
     * {@see \Psalm\LaravelPlugin\Providers\MacroRegistry::reset()}.
     *
     * @psalm-external-mutation-free Same convention as
     *         {@see \Psalm\LaravelPlugin\Providers\MacroRegistry::reset()} and
     *         the other static-cache resets in this namespace: mutates only
     *         this class's own static state, never anything reachable from
     *         the caller, so for Psalm's purity tracking it counts as
     *         effect-free.
     */
    public static function reset(): void
    {
        self::$cache = [];
    }

    /**
     * Memoized wrapper around {@see ClosureTypeFactory::indexFile()}.
     *
     * Cache key is the resolved path (already canonicalised by the caller
     * inside `buildWithIndexer`). Mtime stamp guards against in-run rewrites
     * (rare, but happens in test fixtures); a fresh mtime forces a re-parse.
     *
     * @return array<int, list<array{0: ?Doc, 1: Aliases}>>|null
     */
    private static function indexFile(string $realpath): ?array
    {
        $mtime = @\filemtime($realpath);
        if ($mtime === false) {
            return null;
        }

        if (isset(self::$cache[$realpath]) && self::$cache[$realpath]['mtime'] === $mtime) {
            return self::$cache[$realpath]['entries'];
        }

        $entries = ClosureTypeFactory::indexFile($realpath);
        self::$cache[$realpath] = ['mtime' => $mtime, 'entries' => $entries];

        return $entries;
    }
}
