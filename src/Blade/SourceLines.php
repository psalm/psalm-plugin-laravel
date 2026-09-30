<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Splits source into lines, keeping each line's own trailing newline, so that
 * `implode('', ...)` round-trips the input byte for byte and a line's 1-based
 * number is its index + 1. Marker injection, line mapping and suppression
 * injection all rewrite lines in place and depend on both properties.
 *
 * A bare `\r` (not part of a `\r\n` pair) ends a line too, matching PHP's own lexer
 * (`token_get_all()`'s `$token[2]` line numbers, which `LineMapBuilder` reads markers off of,
 * count one the same way): splitting on `\n` alone under-counts a bare-CR file's lines, so the map
 * built here falls behind the token line numbers and ends short, leaving every later line unmapped
 * (#1545 review).
 *
 * @internal
 */
final class SourceLines
{
    /** @return list<string> */
    public static function split(string $source): array
    {
        // `(?<=\r)(?!\n)` fires only on a `\r` NOT followed by `\n`, so a `\r\n` pair is never cut
        // in half — the `(?<=\n)` half of the alternation already splits right after its `\n`.
        $lines = \preg_split('/(?<=\n)|(?<=\r)(?!\n)/', $source);
        \assert($lines !== false);

        return $lines;
    }
}
