--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\MechanicSpecialization;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Companion to FindMixedNarrowingTest for #975. The relation classes
 * `BelongsToMany`, `HasManyThrough`, `HasOneOrManyThrough`, and `HasOneOrMany`
 * re-declare find/findOrFail/findOrNew with their own conditionals, so they
 * widen the same way when `$id` is `mixed`. The handler collapses each to the
 * scalar-id branch.
 *
 * For `BelongsToMany`, the scalar-id branch carries the
 * `&object{pivot: TPivotModel}` intersection from the stub. The handler
 * reconstructs that shape from the relation's TPivotModel template (index 2);
 * stripping it would break callers that rely on `$model->pivot` afterwards.
 */
function test_belongs_to_many_find_mixed_narrows_with_pivot(Mechanic $mechanic, mixed $id): void
{
    $_r = $mechanic->specializations()->find($id);
    /** @psalm-check-type-exact $_r = MechanicSpecialization&object{pivot: Pivot}|null */
}

function test_belongs_to_many_find_or_fail_mixed_narrows_with_pivot(Mechanic $mechanic, mixed $id): void
{
    $_r = $mechanic->specializations()->findOrFail($id);
    /** @psalm-check-type-exact $_r = MechanicSpecialization&object{pivot: Pivot} */
}

function test_belongs_to_many_find_or_new_mixed_narrows_with_pivot(Mechanic $mechanic, mixed $id): void
{
    $_r = $mechanic->specializations()->findOrNew($id);
    /** @psalm-check-type-exact $_r = MechanicSpecialization&object{pivot: Pivot} */
}

function test_belongs_to_many_find_or_mixed_narrows_with_pivot(Mechanic $mechanic, mixed $id): void
{
    $_r = $mechanic->specializations()->findOr($id, fn() => 'fallback');
    /** @psalm-check-type-exact $_r = MechanicSpecialization&object{pivot: Pivot}|'fallback' */
}

function test_has_many_through_find_mixed_narrows(Customer $customer, mixed $id): void
{
    $_r = $customer->workOrders()->find($id);
    /** @psalm-check-type-exact $_r = WorkOrder|null */
}

function test_has_many_through_find_or_fail_mixed_narrows(Customer $customer, mixed $id): void
{
    $_r = $customer->workOrders()->findOrFail($id);
    /** @psalm-check-type-exact $_r = WorkOrder */
}

// Positive controls — concrete int + array args still resolve through the
// stub conditional, even on relation paths where the handler is registered.
function test_belongs_to_many_find_int_still_narrows_to_model(Mechanic $mechanic): void
{
    $_r = $mechanic->specializations()->find(123);
    /** @psalm-check-type-exact $_r = MechanicSpecialization&object{pivot: Pivot}|null */
}

function test_belongs_to_many_find_array_still_narrows_to_collection(Mechanic $mechanic): void
{
    $_r = $mechanic->specializations()->find([1, 2, 3]);
    /** @psalm-check-type-exact $_r = Collection<int, MechanicSpecialization&object{pivot: Pivot}>|null */
}

?>
--EXPECTF--
