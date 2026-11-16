<?php

declare(strict_types=1);

namespace CasterReturnFixture\Models;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;

/** A Castable whose `castUsing()` may return either caster; the attribute is whatever either one returns. */
final class MultiCastable implements Castable
{
    /**
     * @param array<array-key, mixed> $arguments
     * @return IntGetCast|StringGetCast
     */
    public static function castUsing(array $arguments): CastsAttributes|CastsInboundAttributes|string
    {
        return new IntGetCast();
    }
}
