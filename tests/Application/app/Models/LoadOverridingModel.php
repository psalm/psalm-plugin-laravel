<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A model that overrides `load()`, so the contract of an eager-load constraint closure is its own, not Laravel's.
 */
final class LoadOverridingModel extends Model
{
    /** @return HasMany<Vehicle, $this> */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /** @inheritDoc */
    #[\Override]
    public function load($relations)
    {
        parent::load($relations);

        return $this;
    }
}
