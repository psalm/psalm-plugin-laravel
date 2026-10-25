<?php

declare(strict_types=1);

namespace AutoloadCrashFixture;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

\trigger_error('deprecated on load', \E_USER_DEPRECATED);

/** @implements CastsAttributes<int, int> */
final class DeprecatedCaster implements CastsAttributes
{
    /** @return int */
    #[\Override]
    public function get(Model $model, string $key, mixed $value, array $attributes): int
    {
        return 0;
    }

    #[\Override]
    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        return 0;
    }
}
