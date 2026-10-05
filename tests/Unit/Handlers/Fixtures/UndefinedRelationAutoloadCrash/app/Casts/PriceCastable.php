<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Casts;

use Illuminate\Contracts\Database\Eloquent\Castable;

final class PriceCastable implements Castable
{
    /** @return DeprecatedPriceCaster Own docblock: otherwise Psalm keeps the interface's wider return type. */
    #[\Override]
    public static function castUsing(array $arguments): DeprecatedPriceCaster
    {
        return new DeprecatedPriceCaster();
    }
}
