<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Psalm\CodeLocation\Raw;

/**
 * A `CodeLocation\Raw` covering one line of a template's source, shared by the shadow remap.
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

        // Raw numbers its line by counting `\n` before the offset. Turning a bare CR into an LF keeps
        // every byte offset and puts a bare-CR template's findings on their own line, not on line 1.
        return new Raw(\preg_replace('/\r(?!\n)/', "\n", $source) ?? $source, $templatePath, $templateName, $bounds[0], $bounds[1]);
    }

    /**
     * Byte offsets of a 1-based line, or null when the source has no such line. Lines split the
     * way {@see SourceLines} does, so a bare-CR template locates the same line the line map named.
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
        $length = \strlen(\rtrim($lines[$line - 1], "\r\n"));

        return [$start, \max($start, $start + $length - 1)];
    }
}
