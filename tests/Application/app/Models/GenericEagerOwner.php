<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A model with template params: Relation types rebuilt from its bare class name would carry arguments the
 * receiver does not.
 *
 * @template TKey of int|string
 */
final class GenericEagerOwner extends Model
{
    /** @return HasMany<Vehicle, $this> */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }
}
