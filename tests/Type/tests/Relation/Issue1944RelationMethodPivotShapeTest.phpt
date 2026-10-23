--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;
use App\Models\WorkOrder;

/**
 * Callers of a registered relation method see its `withPivot()` / `withTimestamps()` columns in TPivotModel (#1944),
 * unless the method declares its own pivot shape. Traces, not check-type: Psalm compares object
 * shapes inside an intersection by name only, so check-type cannot pin the keys.
 */
function relation_method_carries_with_pivot_columns(WorkOrder $order, Mechanic $mechanic): void
{
    $_parts = $order->parts();
    /** @psalm-trace $_parts */

    $_discounted = $order->discountedParts();
    /** @psalm-trace $_discounted */

    $_priced = $order->pricedParts();
    /** @psalm-trace $_priced */

    $_certified = $mechanic->certifiedSpecializations();
    /** @psalm-trace $_certified */

    $_stamped = $order->stampedParts();
    /** @psalm-trace $_stamped */

    $_renamed_stamp = $order->renamedStampParts();
    /** @psalm-trace $_renamed_stamp */

    $_stamped_specializations = $mechanic->stampedSpecializations();
    /** @psalm-trace $_stamped_specializations */

    $_branch_table = $order->branchTableParts();
    /** @psalm-trace $_branch_table */

    $_branch_columns = $order->branchColumnParts();
    /** @psalm-trace $_branch_columns */

    $_branch_table_property = $order->branchTableParts;
    /** @psalm-trace $_branch_table_property */

    $_branch_columns_property = $order->branchColumnParts;
    /** @psalm-trace $_branch_columns_property */
}
?>
--EXPECTF--
Trace on line %d: $_parts: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, App\Models\WorkOrder, Illuminate\Database\Eloquent\Relations\Pivot&object{quantity:mixed, unit_price:mixed}, 'pivot'>
Trace on line %d: $_discounted: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, App\Models\WorkOrder, Illuminate\Database\Eloquent\Relations\Pivot&object{quantity:mixed, unit_price:mixed, discount:mixed}, 'pivot'>
Trace on line %d: $_priced: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, App\Models\WorkOrder, Illuminate\Database\Eloquent\Relations\Pivot&object{quantity:int}, 'pivot'>
Trace on line %d: $_certified: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\MechanicSpecialization, App\Models\Mechanic, App\Models\SpecializationPivot&object{certified_at:null|string}, 'pivot'>
Trace on line %d: $_stamped: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, App\Models\WorkOrder, Illuminate\Database\Eloquent\Relations\Pivot&object{quantity:mixed, created_at:Illuminate\Support\Carbon|null, updated_at:Illuminate\Support\Carbon|null}, 'pivot'>
Trace on line %d: $_renamed_stamp: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, App\Models\WorkOrder, Illuminate\Database\Eloquent\Relations\Pivot&object{quantity:mixed, unit_price:mixed, made_at:mixed, changed_at:mixed}, 'pivot'>
Trace on line %d: $_stamped_specializations: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\MechanicSpecialization, App\Models\Mechanic, App\Models\SpecializationPivot&object{created_at:Illuminate\Support\Carbon|null, updated_at:Illuminate\Support\Carbon|null}, 'pivot'>
Trace on line %d: $_branch_table: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, App\Models\WorkOrder, Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>
Trace on line %d: $_branch_columns: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, App\Models\WorkOrder, Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>
Trace on line %d: $_branch_table_property: App\Collections\PartCollection<int, App\Models\Part>
Trace on line %d: $_branch_columns_property: App\Collections\PartCollection<int, App\Models\Part>
