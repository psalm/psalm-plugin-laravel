<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Splits source into lines, keeping each line's own trailing newline, so that
 * `implode('', ...)` round-trips the input byte for byte and a line's 1-based
 * number is its index + 1. Marker injection, line mapping and suppression
 * injection all rewrite lines in place and depend on both properties.
 *
 * @internal
 */
final class SourceLines
{
    /** @return list<string> */
    public static function split(string $source): array
    {
        $lines = \preg_split('/(?<=\n)/', $source);
        \assert($lines !== false);

        return $lines;
    }
}
