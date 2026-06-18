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
     * @param int $sourceTaints  Taint kinds to (re-)introduce; the stub source
     *                           was dropped by a type override or absent on
     *                           `Request::__get` (#11765). Zero for an alias —
     *                           the underlying accessor already sourced it.
     * @param int $removedTaints Taint kinds the field's rule escapes (e.g.
     *                           `email` escapes header/cookie).
     */
    public function __construct(
        public int $sourceTaints,
        public int $removedTaints,
    ) {}
}
