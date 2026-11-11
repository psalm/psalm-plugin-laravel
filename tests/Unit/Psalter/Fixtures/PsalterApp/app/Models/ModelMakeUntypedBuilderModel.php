<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Its `newEloquentBuilder()` has no native return type, so the plugin cannot resolve the builder class and
 * falls back to the stock Builder, which is no proof: the real builder overrides `make()`. `new` would skip it.
 */
class ModelMakeUntypedBuilderModel extends Model
{
    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @return ModelMakeOverridingBuilder<static>
     */
    #[\Override]
    public function newEloquentBuilder($query)
    {
        return new ModelMakeOverridingBuilder($query);
    }
}
