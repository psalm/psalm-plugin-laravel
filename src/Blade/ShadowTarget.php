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
 *
 * @psalm-immutable
 */
final class ShadowTarget
{
    /**
     * @param string $templateSource the template's bytes, which a `Raw` location indexes into
     * @param string $templateName   the display name Psalm's reporters print for the template
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public readonly ShadowEntry $entry,
        public readonly string $templateSource,
        public readonly string $templateName,
    ) {}

    /**
     * Template line a shadow line came from; 0 for prelude lines and anything the marker pass could not map.
     *
     * @psalm-mutation-free
     */
    public function templateLineFor(int $shadowLine): int
    {
        return $this->entry->lineMap[$shadowLine] ?? 0;
    }

    /**
     * A location covering that template line, or null when the template has no such line.
     *
     * @psalm-mutation-free
     */
    public function locationFor(int $templateLine): ?Raw
    {
        return TemplateLocation::atLine($this->entry->templatePath, $this->templateName, $this->templateSource, $templateLine);
    }
}
