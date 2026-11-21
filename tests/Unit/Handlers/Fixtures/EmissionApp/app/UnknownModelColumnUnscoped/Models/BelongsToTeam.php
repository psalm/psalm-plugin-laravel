<?php

declare(strict_types=1);

namespace UnscopedColumnFixture\Models;

trait BelongsToTeam
{
    public static function bootBelongsToTeam(): void
    {
        static::addGlobalScope(new TeamScope());
    }
}
