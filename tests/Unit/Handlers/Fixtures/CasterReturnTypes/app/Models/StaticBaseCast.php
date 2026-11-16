<?php

declare(strict_types=1);

namespace CasterReturnFixture\Models;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** `get()` returns `static` and `parent` through inheritance; the caster class is not final. */
class StaticBaseCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): static
    {
        return $this;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        return 0;
    }
}
