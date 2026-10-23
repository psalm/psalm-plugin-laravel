<?php

declare(strict_types=1);

namespace WithPivotFixture\Models;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Its `static` is the caster, never the model that reads it.
 *
 * @implements CastsAttributes<static, mixed>
 */
final class ValueCaster implements CastsAttributes
{
    /** @return static */
    #[\Override]
    public function get(Model $model, string $key, mixed $value, array $attributes): static
    {
        return $this;
    }

    #[\Override]
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value;
    }
}
