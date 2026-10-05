<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\CastUsing;

use Illuminate\Contracts\Database\Eloquent\Castable;

final class ClassStringCastable implements Castable
{
    /** @return class-string<DeprecatedClassStringCaster> Own docblock: otherwise Psalm keeps the interface's wider return type. */
    #[\Override]
    public static function castUsing(array $arguments): string
    {
        return DeprecatedClassStringCaster::class;
    }
}
