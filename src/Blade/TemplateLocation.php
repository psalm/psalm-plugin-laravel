<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Psalm\CodeLocation\Raw;

/**
 * A `CodeLocation\Raw` covering one line of a template's source, extracted from
 * {@see ShadowTarget} so an issue can be anchored to a template line without a shadow entry —
 * {@see \Psalm\LaravelPlugin\Handlers\Views\UnusedViewHandler} reports on templates that never
 * compiled at all, which have no shadow to relocate from.
 *
 * @internal
 */
final class TemplateLocation
{
    public static function atLine(string $templatePath, string $templateName, string $source, int $line): ?Raw
    {
        $bounds = self::lineBounds($source, $line);

        if ($bounds === null) {
            return null;
        }

        return new Raw($source, $templatePath, $templateName, $bounds[0], $bounds[1]);
    }

    /**
     * Byte offsets of a 1-based line, or null when the source has no such line.
     *
     * @return array{int, int}|null
     */
    public static function lineBounds(string $source, int $line): ?array
    {
        $start = 0;

        for ($current = 1; $current < $line; $current++) {
            $newline = \strpos($source, "\n", $start);

            if ($newline === false) {
                return null;
            }

            $start = $newline + 1;
        }

        if ($start > \strlen($source)) {
            return null;
        }

        $newline = \strpos($source, "\n", $start);
        $end = $newline === false ? \strlen($source) : $newline;

        return [$start, \max($start, $end - 1)];
    }
}
