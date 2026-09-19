<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Turns a template's absolute path into the view name Laravel would resolve it under, mirroring
 * `FileViewFinder`: the first root that contains the file owns the name, and the name is the path
 * under that root with the extension dropped and separators turned into dots.
 *
 * Extracted so template-side name resolution and reference-side name resolution can never drift —
 * a byte-for-byte mismatch between the two makes every UnusedView verdict a false positive.
 *
 * @internal
 *
 * @psalm-pure
 */
final class ViewName
{
    /**
     * @param list<string> $roots realpaths, in finder order
     *
     * @return array{0: int, 1: string}|null null when the path is under none of the roots
     *
     * @psalm-pure
     */
    public static function resolve(string $templatePath, array $roots): ?array
    {
        foreach ($roots as $index => $root) {
            $prefix = $root . \DIRECTORY_SEPARATOR;

            if (!\str_starts_with($templatePath, $prefix)) {
                continue;
            }

            $relative = \substr($templatePath, \strlen($prefix), -\strlen('.blade.php'));

            return [$index, \str_replace(\DIRECTORY_SEPARATOR, '.', $relative)];
        }

        return null;
    }
}
