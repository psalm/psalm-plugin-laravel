<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A model that overrides its static magic forwarding, which a static `Model::withOnly()` goes through. */
final class ForwardingModel extends Model
{
    /** @return HasMany<Vehicle, $this> */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /** @inheritDoc */
    #[\Override]
    public static function __callStatic($method, $parameters)
    {
        return parent::__callStatic($method, $parameters);
    }
}
