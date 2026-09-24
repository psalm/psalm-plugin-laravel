<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

/**
 * Rewrites a path's segments to match the filesystem's own casing, one component at a time.
 *
 * `realpath()` on macOS APFS PRESERVES the caller's casing (it resolves symlinks and `.`/`..`
 * segments, nothing else about case) — so it cannot collapse `Resources/views` and
 * `resources/views` into one spelling even though a case-insensitive filesystem opens both as the
 * same directory. Two hint sources that name the same physical Blade view root with different case
 * therefore realpath to two DIFFERENT strings, and everything keyed on that string (root dedup,
 * template discovery, manifest entries) treats them as two roots (#1552).
 *
 * `scandir()` returns entries in their real on-disk casing regardless of how the filesystem opens
 * them, which is what makes this fixable: for each segment, an EXACT dirent match always wins; a
 * case-insensitive match is used only when no exact one exists, and only when it is unique. This
 * is deliberate, not incidental: on a case-SENSITIVE filesystem both spellings are real, distinct
 * dirents, an exact match exists for whichever one was asked for, and the two paths stay distinct —
 * which is required, not a bug, on that filesystem.
 *
 * @internal
 */
final class PathCaseCanonicalizer
{
    /**
     * Dirent listings for this Psalm invocation, keyed by directory, `false` for one that could not
     * be listed. Callers canonicalize once per discovered template against a handful of view roots,
     * so without this every template re-reads the same directories from the top down.
     *
     * @var array<string, list<string>|false>
     */
    private static array $entriesByDirectory = [];

    /**
     * Never fails: a segment that cannot be listed (missing path, permission denied) or that
     * matches nothing/more than one dirent case-insensitively is returned as given, for that
     * segment onward. Callers that feed this a nonexistent path get the same path back unchanged.
     *
     * Only POSIX absolute paths are walked. A Windows path (`C:\...`, `\\server\share`) or a
     * relative path is returned unchanged: walking one from `scandir('.')` would resolve segments
     * against the WRONG directory (the process cwd, or the drive's own cwd) and could rewrite a
     * segment against unrelated dirents. Windows therefore keeps its pre-canonicalization
     * behavior — case-variant duplicates persist there until a drive/UNC-aware walk exists.
     */
    public static function canonicalize(string $path): string
    {
        if (!\str_starts_with($path, '/')) {
            return $path;
        }

        // Only a spelling this filesystem actually opens gets rewritten. Anything else must pass
        // through: on a case-SENSITIVE filesystem holding only `Resources`, the fallback below
        // would answer `resources` with the sibling and hand callers a view root the application
        // itself cannot read; a trailing separator on a file ('/x/composer.json/') is refused for
        // the same reason. `file_exists()` case-folds exactly where the rewrite is wanted, so the
        // legitimate case-variant collapses are unaffected.
        if (!\file_exists($path)) {
            return $path;
        }

        $segments = \array_values(\array_filter(
            \explode('/', $path),
            static fn(string $segment): bool => $segment !== '',
        ));

        $walked = '/';
        $canonicalSegments = [];

        foreach ($segments as $segment) {
            $entries = self::$entriesByDirectory[$walked] ??= @\scandir($walked);

            if ($entries === false) {
                // Cannot list this level at all: keep the ORIGINAL path, not a half-canonicalized
                // prefix — a caller comparing this against another path needs all-or-nothing.
                return $path;
            }

            $resolved = self::resolveSegment($entries, $segment);
            $canonicalSegments[] = $resolved;
            $walked = $walked === '/' ? $walked . $resolved : $walked . '/' . $resolved;
        }

        return '/' . \implode('/', $canonicalSegments);
    }

    /** Dropped per Psalm invocation: the memo above is a within-run cache, not a view of the disk. */
    public static function reset(): void
    {
        self::$entriesByDirectory = [];
    }

    /** @param list<string> $entries */
    private static function resolveSegment(array $entries, string $segment): string
    {
        if (\in_array($segment, $entries, true)) {
            return $segment;
        }

        $caseInsensitiveMatches = \array_values(\array_filter(
            $entries,
            static fn(string $entry): bool => \strcasecmp($entry, $segment) === 0,
        ));

        return \count($caseInsensitiveMatches) === 1 ? $caseInsensitiveMatches[0] : $segment;
    }
}
