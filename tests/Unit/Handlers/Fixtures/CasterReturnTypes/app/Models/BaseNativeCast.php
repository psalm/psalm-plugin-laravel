<?php

declare(strict_types=1);

namespace CasterReturnFixture\Models;

use CasterReturnFixture\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** `get()` carries a native return type only and is inherited as is by {@see ChildNativeCast}. */
class BaseNativeCast implements CastsAttributes
{
    #[\Override]
    public function get(Model $model, string $key, mixed $value, array $attributes): Money
    {
        return new Money((int) $value);
    }

    #[\Override]
    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        return 0;
    }
}
