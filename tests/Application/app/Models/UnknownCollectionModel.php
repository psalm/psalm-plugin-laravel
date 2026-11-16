<?php

declare(strict_types=1);

namespace App\Models;

use App\Collections\ChainOverridingCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `newCollection()` with a docblock-only return type: the plugin's detector needs a native named return type, so
 * the real collection (here one that overrides `loadMissingRelation()`) is unknown.
 */
final class UnknownCollectionModel extends Model
{
    /** @return HasMany<Vehicle, $this> */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /**
     * @param array<array-key, Model> $models
     * @return ChainOverridingCollection<array-key, Model&static>
     */
    #[\Override]
    public function newCollection(array $models = [])
    {
        /** @var ChainOverridingCollection<array-key, Model&UnknownCollectionModel> $collection */
        $collection = new ChainOverridingCollection($models);

        return $collection;
    }
}
