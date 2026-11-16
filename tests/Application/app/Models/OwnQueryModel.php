<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A model that overrides a method building its eager-load query, so it keeps its own contract. */
final class OwnQueryModel extends Model
{
    /** @return HasMany<Vehicle, $this> */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /**
     * @return Builder<static>
     */
    #[\Override]
    public function newQueryWithoutRelationships()
    {
        return parent::newQueryWithoutRelationships();
    }
}
