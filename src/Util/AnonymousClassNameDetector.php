<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util;

/**
 * Detects the synthetic FQCN Psalm assigns to anonymous classes. Psalm builds
 * them as `{sanitized_file_path}_{line}_{startFilePos}` (prefixed by the
 * surrounding namespace), and they are never autoloadable.
 *
 * Shared between handlers that iterate ClassLikeStorage (registration handlers,
 * stats) and need to skip synthesised names.
 *
 * @see \Psalm\Internal\Analyzer\ClassAnalyzer::getAnonymousClassName()
 * @internal
 * @psalm-immutable
 */
final class AnonymousClassNameDetector
{
    /** @psalm-pure */
    public static function isSynthetic(string $fqcn, string $filePath): bool
    {
        if ($filePath === '') {
            return false;
        }

        $lastSeparator = \strrpos($fqcn, '\\');
        $shortName = $lastSeparator === false ? $fqcn : \substr($fqcn, $lastSeparator + 1);

        // Quick reject: every synthetic anonymous name ends in `_<line>_<startFilePos>`.
        // Real class names (User, Post, ...) fail this in O(1), letting us skip
        // the more expensive path-sanitisation below for ~all real classlikes.
        if (\preg_match('/_\d+_\d+$/', $shortName) !== 1) {
            return false;
        }

        // Mirrors the sanitisation in ClassAnalyzer::getAnonymousClassName().
        $sanitizedPath = \preg_replace('/[^A-Za-z0-9]/', '_', $filePath) ?? '';

        return \str_starts_with($shortName, $sanitizedPath . '_');
    }
}
