<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Psalm\CodeLocation\Raw;

/**
 * A `CodeLocation\Raw` covering the non-blank span of one template line (column = first non-blank
 * character), shared by the shadow remap.
 *
 * @internal
 *
 * @psalm-immutable
 */
final class TemplateLocation
{
    /**
     * @psalm-pure
     */
    public static function atLine(string $templatePath, string $templateName, string $source, int $line): ?Raw
    {
        $bounds = self::lineBounds($source, $line);

        if ($bounds === null) {
            return null;
        }

        return new Raw($source, $templatePath, $templateName, $bounds[0], $bounds[1]);
    }

    /**
     * Byte offsets of a 1-based line's non-blank span, or null when the source has no such line. A
     * whitespace-only line keeps its full bounds. Lines split the way {@see SourceLines} does, so a
     * bare-CR template locates the same line the line map named.
     *
     * @return array{int, int}|null
     *
     * @psalm-pure
     */
    private static function lineBounds(string $source, int $line): ?array
    {
        $lines = SourceLines::split($source);

        if ($line < 1 || !isset($lines[$line - 1])) {
            return null;
        }

        $start = \strlen(\implode('', \array_slice($lines, 0, $line - 1)));
        // A line holds no `\r`/`\n` besides its own terminator, so this strips exactly LF, CRLF or bare CR.
        $text = \rtrim($lines[$line - 1], "\r\n");
        $trimmed = \rtrim($text, " \t");

        if ($trimmed === '') {
            return [$start, \max($start, $start + \strlen($text) - 1)];
        }

        // Skipping indentation keeps the reported column and console highlight on the code itself.
        return [$start + \strspn($text, " \t"), $start + \strlen($trimmed) - 1];
    }
}
