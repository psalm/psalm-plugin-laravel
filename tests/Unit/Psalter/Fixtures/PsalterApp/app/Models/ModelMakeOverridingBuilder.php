<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent builder whose `make()` does extra work that `new` would skip.
 *
 * @template TModel of Model
 * @extends Builder<TModel>
 */
class ModelMakeOverridingBuilder extends Builder
{
    /**
     * @param array<string, mixed> $attributes
     * @return TModel
     */
    #[\Override]
    public function make(array $attributes = [])
    {
        $model = parent::make($attributes);
        $model->setAttribute('marker', 'builder');

        return $model;
    }
}
