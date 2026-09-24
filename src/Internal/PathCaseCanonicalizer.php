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
     * Never fails: a segment that cannot be listed (missing path, permission denied) or that
     * matches nothing/more than one dirent case-insensitively is returned as given, for that
     * segment onward. Callers that feed this a nonexistent path get the same path back unchanged.
     */
    public static function canonicalize(string $path): string
    {
        $isAbsolute = \str_starts_with($path, \DIRECTORY_SEPARATOR);
        $segments = \array_values(\array_filter(
            \explode(\DIRECTORY_SEPARATOR, $path),
            static fn(string $segment): bool => $segment !== '',
        ));

        $walked = $isAbsolute ? \DIRECTORY_SEPARATOR : '';
        $canonicalSegments = [];

        foreach ($segments as $segment) {
            $entries = @\scandir($walked === '' ? '.' : $walked);

            if ($entries === false) {
                // Cannot list this level at all: keep the ORIGINAL path, not a half-canonicalized
                // prefix — a caller comparing this against another path needs all-or-nothing.
                return $path;
            }

            $resolved = self::resolveSegment($entries, $segment);
            $canonicalSegments[] = $resolved;
            $walked = ($walked === '' || $walked === \DIRECTORY_SEPARATOR)
                ? $walked . $resolved
                : $walked . \DIRECTORY_SEPARATOR . $resolved;
        }

        return ($isAbsolute ? \DIRECTORY_SEPARATOR : '') . \implode(\DIRECTORY_SEPARATOR, $canonicalSegments);
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
