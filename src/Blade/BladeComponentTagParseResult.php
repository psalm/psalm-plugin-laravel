<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Output of {@see BladeComponentTagParser::parse()}.
 *
 * The parser emits a tri-axis result so the scanner can decide independently
 * whether to emit {@see BladeUncertaintyReason::ComponentResolved} (any
 * records present), {@see BladeUncertaintyReason::ComponentTag} (any
 * unresolvable construct seen), or both (mixed-tag templates).
 *
 * The two flags are not mutually exclusive: a template with one resolvable
 * `<x-foo />` and one unresolvable `<x-bar>...</x-bar>` produces a non-empty
 * records list AND `hasUnresolvable === true`. The scanner adds both reasons;
 * propagation in {@see BladeSafetyMap} will see ComponentTag dominating and
 * treat the parent as UNKNOWN regardless of the recorded edges.
 *
 * @internal
 *
 * @psalm-immutable
 */
final readonly class BladeComponentTagParseResult
{
    /**
     * @param list<BladeComponentTagRecord> $records         resolvable
     *                                                       self-closing `<x-foo ... />` tags
     * @param bool                          $hasUnresolvable true when any opening tag, namespaced tag,
     *                                                       dynamic-component, `@component` / `@slot`
     *                                                       directive, or unparseable attribute was seen
     */
    public function __construct(
        public array $records,
        public bool $hasUnresolvable,
    ) {}
}
