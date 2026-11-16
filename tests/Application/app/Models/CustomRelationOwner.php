<?php

declare(strict_types=1);

namespace App\Models;

use App\Relations\QueryOverridingHasMany;
use App\Relations\SwappedHasMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Owner of userland Relation subclasses: an eager-load constraint is only typed through Laravel's own relation
 * classes, on every segment of a path.
 */
final class CustomRelationOwner extends Model
{
    /** @return QueryOverridingHasMany<Vehicle, $this> */
    public function vehicles(): QueryOverridingHasMany
    {
        /** @var QueryOverridingHasMany<Vehicle, CustomRelationOwner> $relation */
        $relation = new QueryOverridingHasMany(Vehicle::query(), $this, 'customer_id', 'id');

        return $relation;
    }

    /** @return SwappedHasMany<CustomRelationOwner, Vehicle> */
    public function swapped(): SwappedHasMany
    {
        /** @var SwappedHasMany<CustomRelationOwner, Vehicle> $relation */
        $relation = new SwappedHasMany(Vehicle::query(), $this, 'customer_id', 'id');

        return $relation;
    }

    /** @return BelongsToMany<WorkOrder, $this> */
    public function workOrders(): BelongsToMany
    {
        return $this->belongsToMany(WorkOrder::class);
    }
}
