<?php

declare(strict_types=1);

namespace CasterReturnFixture\Models;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;

final class DualContractCastable implements Castable
{
    /**
     * @param array<array-key, mixed> $arguments
     * @return DualContractCast
     */
    public static function castUsing(array $arguments): CastsAttributes|CastsInboundAttributes|string
    {
        return new DualContractCast();
    }
}
