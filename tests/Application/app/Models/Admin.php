<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Second Authenticatable for multi-guard testing.
 *
 * Also serves as the polymorphic bookmarker — Admin bookmarks
 * Customers, Mechanics, and Suppliers via MorphToMany.
 */
class Admin extends Authenticatable
{
    /**
     * @psalm-return MorphToMany<Customer>
     */
    public function customers(): MorphToMany
    {
        return $this->morphToMany(Customer::class, 'bookmarkable');
    }

    /**
     * @psalm-return MorphToMany<Mechanic>
     */
    public function mechanics(): MorphToMany
    {
        return $this->morphToMany(Mechanic::class, 'bookmarkable');
    }

    /**
     * @psalm-return MorphToMany<Supplier>
     */
    public function suppliers(): MorphToMany
    {
        return $this->morphToMany(Supplier::class, 'bookmarkable');
    }

    /**
     * Bookmarked work orders — used to test MorphToMany with generics + custom collection.
     * WorkOrder has WorkOrderCollection via #[CollectedBy].
     *
     * @psalm-return MorphToMany<WorkOrder>
     */
    public function workOrders(): MorphToMany
    {
        return $this->morphToMany(WorkOrder::class, 'bookmarkable');
    }
}
