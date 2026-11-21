<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

trait HasJoinScope
{
    public static function bootHasJoinScope(): void
    {
        static::addGlobalScope(new JoinScope());
    }
}
