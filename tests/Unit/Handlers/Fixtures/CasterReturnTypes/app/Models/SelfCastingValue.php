<?php

declare(strict_types=1);

namespace CasterReturnFixture\Models;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Both Castable and its own caster. Laravel uses `castUsing()` only, and its return type is conditional (unresolved
 * for us), so the attribute is unknown and must not fall back to this class's own `get()`.
 */
final class SelfCastingValue implements Castable, CastsAttributes
{
    /**
     * @param array<array-key, mixed> $arguments
     * @return ($arguments is non-empty-array ? IntGetCast : StringGetCast)
     */
    public static function castUsing(array $arguments): CastsAttributes|CastsInboundAttributes|string
    {
        return $arguments === [] ? new StringGetCast() : new IntGetCast();
    }

    #[\Override]
    public function get(Model $model, string $key, mixed $value, array $attributes): self
    {
        return $this;
    }

    #[\Override]
    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        return 0;
    }
}
