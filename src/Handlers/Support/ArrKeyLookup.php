<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Support;

use Psalm\Type\Union;

/**
 * Where `Arr::get()` / `Arr::pull()` end up for a literal key on a sealed array shape.
 *
 * @internal
 *
 * @psalm-immutable
 */
final readonly class ArrKeyLookup
{
    /**
     * @param ?Union $value the value type with `possibly_undefined` stripped, or null when the key is
     *                      definitely absent (the call then always yields its default)
     * @param bool $maybeAbsent the key may also be absent at runtime, so the default can be returned too
     */
    public function __construct(
        public ?Union $value,
        public bool $maybeAbsent,
    ) {}

    public function isDefinitelyAbsent(): bool
    {
        return !$this->value instanceof Union;
    }
}
