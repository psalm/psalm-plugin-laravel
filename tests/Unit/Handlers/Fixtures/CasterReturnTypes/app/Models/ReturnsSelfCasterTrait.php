<?php

declare(strict_types=1);

namespace CasterReturnFixture\Models;

use Illuminate\Database\Eloquent\Model;

/** `self` in a trait method names the consuming caster, never the model. */
trait ReturnsSelfCasterTrait
{
    public function get(Model $model, string $key, mixed $value, array $attributes): self
    {
        return $this;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        return 0;
    }
}
