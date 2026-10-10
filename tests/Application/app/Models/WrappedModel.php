<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\CallbackWrappingBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A model whose custom builder overrides whereHas() with a different callback contract. Static calls and
 * relation-forwarded calls dispatch through the base Eloquent Builder, so the override is only reachable via
 * the model's own builder class.
 */
final class WrappedModel extends Model
{
    protected $table = 'wrapped_models';

    /**
     * @psalm-return HasMany<Part, $this>
     */
    public function parts(): HasMany
    {
        return $this->hasMany(Part::class);
    }

    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @return CallbackWrappingBuilder<static>
     */
    #[\Override]
    public function newEloquentBuilder($query): CallbackWrappingBuilder
    {
        return new CallbackWrappingBuilder($query);
    }
}
