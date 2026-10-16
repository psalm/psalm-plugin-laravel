<?php

declare(strict_types=1);

namespace DynamicWhereFixture\Concerns;

trait WhereState
{
    public static function whereState(string $state): int
    {
        return \strlen($state);
    }
}
