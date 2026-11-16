<?php

declare(strict_types=1);

namespace CasterReturnFixture\Models;

use Illuminate\Database\Eloquent\Model;

/** `static` in a trait method names the consuming caster, never the model. */
trait ReturnsStaticCasterTrait
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
