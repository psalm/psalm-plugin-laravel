<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\CastUsing;

use Illuminate\Contracts\Database\Eloquent\Castable;

final class InboundCastable implements Castable
{
    /** @return DeprecatedInboundCaster Own docblock: otherwise Psalm keeps the interface's wider return type. */
    #[\Override]
    public static function castUsing(array $arguments): DeprecatedInboundCaster
    {
        return new DeprecatedInboundCaster();
    }
}
