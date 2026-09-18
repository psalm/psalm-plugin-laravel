<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Psalm\CodeLocation\Raw;

/**
 * One resolved shadow: its manifest entry plus everything needed to build a location on the
 * template that produced it. Resolving a shadow path means a registry hit AND a readable template,
 * so the two travel together rather than being re-checked at each use.
 *
 * A taint journey crosses files, so the remap resolves a target per journey step, not once per
 * issue — which is why the lookup lives behind a value object instead of the handler's locals.
 *
 * @internal
 */
final class ShadowTarget
{
    /**
     * @param string $templateSource the template's bytes, which a `Raw` location indexes into
     * @param string $templateName   the display name Psalm's reporters print for the template
     */
    public function __construct(
        public readonly ShadowEntry $entry,
        public readonly string $templateSource,
        public readonly string $templateName,
    ) {}

    /** Template line a shadow line came from; 0 for prelude lines and anything the marker pass could not map. */
    public function templateLineFor(int $shadowLine): int
    {
        return $this->entry->lineMap[$shadowLine] ?? 0;
    }

    /** A location covering that template line, or null when the template has no such line. */
    public function locationFor(int $templateLine): ?Raw
    {
        $bounds = $this->lineBounds($templateLine);

        if ($bounds === null) {
            return null;
        }

        return new Raw(
            $this->templateSource,
            $this->entry->templatePath,
            $this->templateName,
            $bounds[0],
            $bounds[1],
        );
    }

    /**
     * Byte offsets of a 1-based line, or null when the source has no such line.
     *
     * @return array{int, int}|null
     */
    private function lineBounds(int $line): ?array
    {
        $start = 0;

        for ($current = 1; $current < $line; $current++) {
            $newline = \strpos($this->templateSource, "\n", $start);

            if ($newline === false) {
                return null;
            }

            $start = $newline + 1;
        }

        if ($start > \strlen($this->templateSource)) {
            return null;
        }

        $newline = \strpos($this->templateSource, "\n", $start);
        $end = $newline === false ? \strlen($this->templateSource) : $newline;

        return [$start, \max($start, $end - 1)];
    }
}
