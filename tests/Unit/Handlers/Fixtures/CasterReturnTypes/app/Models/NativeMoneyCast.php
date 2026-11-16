<?php

declare(strict_types=1);

namespace CasterReturnFixture\Models;

use CasterReturnFixture\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** No `@implements` and no docblock: `get()` is typed by its native return type alone. */
final class NativeMoneyCast implements CastsAttributes
{
    #[\Override]
    public function get(Model $model, string $key, mixed $value, array $attributes): Money
    {
        return new Money((int) $value);
    }

    #[\Override]
    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        return $value->cents;
    }
}
