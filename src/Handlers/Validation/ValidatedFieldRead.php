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
     * Psalm 6 represents taints as a `list<string>` of kind names (not the
     * Psalm 7 int bitmask), so both fields are arrays. Empty list = none.
     *
     * @param list<string> $sourceTaints  Taint kinds to (re-)introduce; the stub
     *                           source was dropped by a type override or absent on
     *                           `Request::__get` (#11765). Empty for an alias —
     *                           the underlying accessor already sourced it.
     * @param list<string> $removedTaints Taint kinds the field's rule escapes
     *                           (e.g. `email` escapes header/cookie).
     */
    public function __construct(
        public array $sourceTaints,
        public array $removedTaints,
    ) {}
}
