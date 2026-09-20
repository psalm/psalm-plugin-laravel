<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Turns a template's absolute path into every view name Laravel would resolve it under, mirroring
 * `FileViewFinder`: a root's name is the path under it with the extension dropped and separators
 * turned into dots, namespace-qualified (`ns::dot.path`) when the root came from a finder hint.
 *
 * A template can legitimately own more than one name at once: a published override
 * (`resources/views/vendor/pkg/x.blade.php`) sits under both the default root (`vendor.pkg.x`) and
 * the namespace's own hint root (`pkg::x`), so every matching root is resolved, not just the first.
 *
 * Extracted so template-side name resolution and reference-side name resolution can never drift —
 * a byte-for-byte mismatch between the two makes every UnusedView verdict a false positive.
 *
 * @internal
 */
final class ViewName
{
    /**
     * @param list<array{0: string, 1: string|null}> $roots realpath, namespace pairs, in finder order
     *
     * @return list<array{0: int, 1: string}> empty when the path is under none of the roots
     */
    public static function resolve(string $templatePath, array $roots): array
    {
        $names = [];

        foreach ($roots as $index => [$root, $namespace]) {
            $prefix = $root . \DIRECTORY_SEPARATOR;

            if (!\str_starts_with($templatePath, $prefix)) {
                continue;
            }

            $relative = \substr($templatePath, \strlen($prefix), -\strlen('.blade.php'));
            $dotted = \str_replace(\DIRECTORY_SEPARATOR, '.', $relative);
            $names[] = [$index, $namespace === null ? $dotted : $namespace . '::' . $dotted];
        }

        return $names;
    }
}
