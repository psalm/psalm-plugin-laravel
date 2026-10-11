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
 *
 * @psalm-immutable
 */
final class SourceLines
{
    /**
     * @return list<string>
     *
     * @psalm-pure
     */
    public static function split(string $source): array
    {
        // `(?<=\r)(?!\n)` fires only on a `\r` NOT followed by `\n`, so a `\r\n` pair is never cut
        // in half — the `(?<=\n)` half of the alternation already splits right after its `\n`.
        $lines = \preg_split('/(?<=\n)|(?<=\r)(?!\n)/', $source);
        \assert($lines !== false);

        return $lines;
    }

    /**
     * Line terminators in `$source[$offset, $offset + $length)`, counted the way {@see self::split()}
     * cuts lines: `\n`, `\r\n` and a bare `\r` each end one. A `\r` that is the range's last byte
     * counts as a bare CR even when a `\n` follows outside the range; callers measure up to a
     * directive or token start, never into a terminator.
     *
     * @psalm-pure
     */
    public static function breaksIn(string $source, int $offset = 0, ?int $length = null): int
    {
        return \substr_count($source, "\n", $offset, $length)
            + \substr_count($source, "\r", $offset, $length)
            - \substr_count($source, "\r\n", $offset, $length);
    }
}
