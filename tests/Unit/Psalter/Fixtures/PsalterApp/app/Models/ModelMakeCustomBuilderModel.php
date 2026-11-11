<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * No constructor of its own, but its Eloquent builder overrides `make()`: `new` would skip that override,
 * so the call is reported and not rewritten.
 */
class ModelMakeCustomBuilderModel extends Model
{
    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @return ModelMakeOverridingBuilder<static>
     */
    #[\Override]
    public function newEloquentBuilder($query): ModelMakeOverridingBuilder
    {
        return new ModelMakeOverridingBuilder($query);
    }
}
