<?php

declare(strict_types=1);

namespace CasterReturnFixture\Models;

use Illuminate\Database\Eloquent\Model;

/** `parent` in a trait method is the parent of the class that imported the trait. */
trait ReturnsParentCasterTrait
{
    public function get(Model $model, string $key, mixed $value, array $attributes): parent
    {
        return new LegacyBase();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        return 0;
    }
}
