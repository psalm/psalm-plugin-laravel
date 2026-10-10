<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Everything the issue remap needs about one compiled shadow: which template produced it, where
 * each of its lines came from, and which suppressions the template asked for.
 *
 * Suppressions are keyed by the TEMPLATE line of the statement they attach to, not by the line the
 * `{{-- @psalm-suppress X --}}` comment sits on — the remap matches them against the line an issue
 * lands on, and Blade compiles the comment itself away.
 *
 * @psalm-immutable
 *
 * @internal
 */
final class ShadowEntry
{
    /** Line-map value of a prelude line copied from a component's `render()` ({@see ComponentViewMap}). */
    public const RENDER_DATA_LINE = -1;

    /**
     * @param array<int, int>         $lineMap      shadow line (1-based) => template line; 0 for prelude
     *                                              lines, {@see self::RENDER_DATA_LINE} for render() data
     * @param array<int, list<string>> $suppressions template line => suppressed issue types
     */
    public function __construct(
        public readonly string $templatePath,
        public readonly array $lineMap,
        public readonly array $suppressions,
    ) {}

    /**
     * Whether the prelude copied a class component's render() data, i.e. the view is proven to be a
     * Blade class-component view, which Laravel evaluates inside a `static` closure.
     */
    public function rendersComponentClass(): bool
    {
        return \in_array(self::RENDER_DATA_LINE, $this->lineMap, true);
    }
}
