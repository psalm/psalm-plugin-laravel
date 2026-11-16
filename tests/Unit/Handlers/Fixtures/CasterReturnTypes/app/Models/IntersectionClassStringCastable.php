<?php

declare(strict_types=1);

namespace CasterReturnFixture\Models;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;

final class IntersectionClassStringCastable implements Castable
{
    /**
     * @param array<array-key, mixed> $arguments
     * @return class-string<IntersectionBaseCast&CastsInboundAttributes>
     */
    public static function castUsing(array $arguments): CastsAttributes|CastsInboundAttributes|string
    {
        throw new \LogicException();
    }
}
