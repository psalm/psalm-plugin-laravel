<?php

declare(strict_types=1);

namespace CasterReturnFixture\Models;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

final class StringGetCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): string
    {
        return "";
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        return 0;
    }
}
