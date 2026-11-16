<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\CombiningBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A model whose registered custom builder overrides `combineConstraints()`, which eager-load constraint closures
 * pass through.
 */
final class CombiningModel extends Model
{
    protected $table = 'combining_models';

    /**
     * @psalm-return HasMany<Vehicle, $this>
     */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @return CombiningBuilder<static>
     */
    #[\Override]
    public function newEloquentBuilder($query): CombiningBuilder
    {
        return new CombiningBuilder($query);
    }
}
