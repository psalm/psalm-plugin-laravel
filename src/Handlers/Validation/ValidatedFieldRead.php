<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

/**
 * One validated-request read, resolved from any syntax (keyed accessor, magic
 * property, or aliased local) by {@see ValidatedFieldReadResolver::resolve}.
 *
 * @psalm-immutable
 */
final readonly class ValidatedFieldRead
{
    /**
     * @param int $sourceTaints  Taint kinds to introduce where no stub source
     *                           applies (`validate()` pseudo-method, magic
     *                           property read). Zero for an alias and for
     *                           stubbed accessors, which carry their own.
     * @param int $removedTaints Taint kinds the field's rule escapes (e.g.
     *                           `email` escapes header/cookie).
     */
    public function __construct(
        public int $sourceTaints,
        public int $removedTaints,
    ) {}
}
