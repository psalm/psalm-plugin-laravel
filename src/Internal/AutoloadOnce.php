<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

/**
 * Runtime autoload of a class Psalm never scanned, attempted at most once per process (#1652).
 *
 * A file that fails mid-load (Psalm's error handler throws on a deprecation it raises) keeps what it declared
 * before the failure, so including it again dies with an uncatchable "Cannot redeclare" fatal. Callers run once
 * per cast, facade call or `freshTimestamp()`, so a retry is otherwise guaranteed.
 *
 * Callers read the outcome through declared-class checks only (`class_exists($class, false)`, ...): a class
 * that failed to load is then simply unknown.
 *
 * @internal
 */
final class AutoloadOnce
{
    /**
     * Class names whose autoload was already attempted, keyed by lowercase name.
     *
     * Deliberately not reset per invocation (code-patterns.md: process-invariant caches need no reset):
     * it mirrors PHP's process state, which a partial load cannot undo, so a re-invocation in the same
     * process must not retry the include either.
     *
     * @var array<lowercase-string, true>
     */
    private static array $attempted = [];

    public static function attempt(string $class): void
    {
        // A leading backslash is the same class: two spellings must not mean two attempts.
        $class = \ltrim($class, '\\');
        $key = \strtolower($class);
        if (isset(self::$attempted[$key])) {
            return;
        }

        self::$attempted[$key] = true;

        try {
            // One autoload declares the target whatever its kind (class, interface or enum).
            \class_exists($class);
        } catch (\Throwable) {
            // Failed to load: the declared-class checks then find nothing, an unknown class.
        }
    }
}
