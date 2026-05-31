<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Raw structured output for one self-closing `<x-foo ... />` tag the
 * {@see BladeComponentTagParser} accepted as resolvable.
 *
 * Holds the dotted/dashed tag name (e.g. `foo`, `foo.bar`) and the
 * partitioned attributes. The scanner converts a list of records into
 * {@see BladeComponentEdge} objects by:
 *
 *   - generating Laravel's three anonymous-component candidate view names
 *     for the tag name;
 *   - filtering each bound attribute's variable list through the visitor's
 *     accumulated scope-locals and the scanner's framework-locals set;
 *   - dropping pure-static attributes (they map to empty bound-var lists,
 *     which means component-edge propagation treats them as "bound to a
 *     non-parent value, do not fall through to verbatim").
 *
 * @internal
 *
 * @psalm-immutable
 */
final readonly class BladeComponentTagRecord
{
    /**
     * @param non-empty-string                                $name     dotted tag name from the source (`foo`,
     *                                                                  `foo.bar`); the leading `x-` / `x:` prefix
     *                                                                  is stripped by the parser
     * @param array{
     *   bound: array<non-empty-string, list<non-empty-string>>,
     *   static: list<non-empty-string>,
     * } $attributes bound and static attribute partitions. The bound map
     *               keys are camelized attribute names; the lists contain
     *               unfiltered top-level `$varname` occurrences from each
     *               attribute's PHP expression. Static lists contain
     *               camelized names with no parent-data flow.
     */
    public function __construct(
        public string $name,
        public array $attributes,
    ) {}
}
