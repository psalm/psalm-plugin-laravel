<?php

declare(strict_types=1);

namespace CasterReturnFixture\Models;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;

/** The inbound contract is declared explicitly in the intersection, so Laravel never calls `get()`. */
final class IntersectionCastable implements Castable
{
    /**
     * @param array<array-key, mixed> $arguments
     * @return IntersectionBaseCast&CastsInboundAttributes
     */
    public static function castUsing(array $arguments): CastsAttributes|CastsInboundAttributes|string
    {
        throw new \LogicException();
    }
}
