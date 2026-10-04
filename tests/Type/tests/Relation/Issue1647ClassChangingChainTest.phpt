--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\DamageReport;
use App\Models\Mechanic;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/1647.
 *
 * A direct factory chain used to report the factory's class even when a chained call changes it:
 * `hasMany()->one()` is a HasOne at runtime. `one()` now maps HasMany / MorphMany / HasManyThrough
 * to their single-result siblings (later calls resolve against the mapped class), a resolvable
 * chain call that leaves the relation (`getQuery()`) declines, and so does a parsed class that is
 * not the declared one.
 */

function issue1647_has_many_one(Shop $shop): HasOne
{
    $relation = $shop->lastWorkOrder();
    /** @psalm-check-type-exact $relation = HasOne<WorkOrder, Shop> */
    return $relation;
}

function issue1647_morph_many_one(Shop $shop): MorphOne
{
    $relation = $shop->lastSupplier();
    /** @psalm-check-type-exact $relation = MorphOne<Supplier, Shop> */
    return $relation;
}

function issue1647_has_many_through_one(Shop $shop): HasOneThrough
{
    $relation = $shop->lastMechanic();
    /** @psalm-check-type-exact $relation = HasOneThrough<Mechanic, Vehicle, Shop> */
    return $relation;
}

function issue1647_one_then_latest_of_many(Shop $shop): HasOne
{
    $relation = $shop->latestOfManyWorkOrder();
    /** @psalm-check-type-exact $relation = HasOne<WorkOrder, Shop> */
    return $relation;
}

function issue1647_has_many_one_property(Shop $shop): ?WorkOrder
{
    /** @psalm-check-type-exact $workOrder = WorkOrder|null */
    $workOrder = $shop->lastWorkOrder;
    return $workOrder;
}

function issue1647_morph_many_one_property(Shop $shop): ?Supplier
{
    /** @psalm-check-type-exact $supplier = Supplier|null */
    $supplier = $shop->lastSupplier;
    return $supplier;
}

function issue1647_has_many_through_one_property(Shop $shop): ?Mechanic
{
    /** @psalm-check-type-exact $mechanic = Mechanic|null */
    $mechanic = $shop->lastMechanic;
    return $mechanic;
}

function issue1647_non_self_chain_call_declines(Shop $shop): Builder
{
    $query = $shop->workOrderQuery();
    /** @psalm-check-type-exact $query = Builder<Model> */
    return $query;
}

function issue1647_non_self_chain_call_declines_without_declared_type(Shop $shop): mixed
{
    /** @psalm-suppress MixedAssignment */
    $query = $shop->untypedWorkOrderQuery();
    /** @psalm-check-type-exact $query = mixed */
    return $query;
}

function issue1647_declared_class_mismatch_declines(Shop $shop): HasOne
{
    $relation = $shop->mismatchedWorkOrder();
    /** @psalm-check-type-exact $relation = HasOne<Model, Model> */
    return $relation;
}
?>
--EXPECTF--
