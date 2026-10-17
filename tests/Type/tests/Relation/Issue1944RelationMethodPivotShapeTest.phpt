--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;
use App\Models\WorkOrder;

/**
 * Callers of a registered relation method see its `withPivot()` columns in TPivotModel (#1944),
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

    $_branch_table = $order->branchTableParts();
    /** @psalm-trace $_branch_table */

    $_branch_columns = $order->branchColumnParts();
    /** @psalm-trace $_branch_columns */
}
?>
--EXPECTF--
Trace on line %d: $_parts: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, App\Models\WorkOrder, Illuminate\Database\Eloquent\Relations\Pivot&object{quantity:mixed, unit_price:mixed}, 'pivot'>
Trace on line %d: $_discounted: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, App\Models\WorkOrder, Illuminate\Database\Eloquent\Relations\Pivot&object{quantity:mixed, unit_price:mixed, discount:mixed}, 'pivot'>
Trace on line %d: $_priced: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, App\Models\WorkOrder, Illuminate\Database\Eloquent\Relations\Pivot&object{quantity:int}, 'pivot'>
Trace on line %d: $_certified: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\MechanicSpecialization, App\Models\Mechanic, App\Models\SpecializationPivot, 'pivot'>
Trace on line %d: $_branch_table: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, App\Models\WorkOrder, Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>
Trace on line %d: $_branch_columns: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, App\Models\WorkOrder, Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>
