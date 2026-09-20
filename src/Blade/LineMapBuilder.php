<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Reads `/* blade:N *\/` markers back out of a shadow file's content to
 * build a shadow-line => blade-source-line map.
 *
 * @internal
 */
final class LineMapBuilder
{
    /**
     * @param int $preludeLines leading lines with no markers of their own (mapped to 0)
     * @return array<int, int> shadow line (1-based) => blade source line (0 for prelude lines)
     */
    public static function build(string $content, int $preludeLines = 0, string $markerPrefix = 'blade:'): array
    {
        $markers = [];
        foreach (\token_get_all($content) as $token) {
            $sourceLine = MarkerComment::sourceLine($token, $markerPrefix);
            if (\is_array($token) && $sourceLine !== null) {
                $markers[$token[2]] = $sourceLine;
            }
        }

        $map = [];
        $last = 1;

        foreach (SourceLines::split($content) as $index => $_line) {
            $lineNumber = $index + 1;

            if ($lineNumber <= $preludeLines) {
                $map[$lineNumber] = 0;

                continue;
            }

            // Last marker wins: a line can carry several (e.g. the trailing
            // `@extends` marker landing on the same line as a real one).
            $last = $markers[$lineNumber] ?? $last;

            $map[$lineNumber] = $last;
        }

        return $map;
    }
}
