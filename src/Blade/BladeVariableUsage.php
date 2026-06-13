<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Records where a variable is referenced in a Blade template, distinguishing
 * safe (auto-escaped) from unsafe (raw-echo) contexts.
 *
 * A variable may appear in both contexts within a single template — for example
 * `{{ $title }}` on one line and `{!! $title !!}` on another. The distinction is
 * per-occurrence, not per-variable: the scanner answers "is at least one echo
 * of $title raw?" rather than "is $title considered unsafe everywhere?".
 *
 * @psalm-api
 * @psalm-immutable
 */
final readonly class BladeVariableUsage
{
    /**
     * @param non-empty-string $name variable name without the leading `$`
     * @param int<1, max> $line 1-based line number in the source template
     */
    public function __construct(
        public string $name,
        public int $line,
        public BladeEchoKind $kind,
    ) {}
}
