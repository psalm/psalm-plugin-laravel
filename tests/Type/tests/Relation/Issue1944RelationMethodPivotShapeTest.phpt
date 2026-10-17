--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;
use App\Models\WorkOrder;

/**
 * Callers of a registered relation method see its `withPivot()` columns in TPivotModel (#1944),
 * unless the method declares its own pivot shape.
 */
function relation_method_carries_with_pivot_columns(WorkOrder $order): void
{
    $_parts = $order->parts();
    /** @psalm-check-type-exact $_parts = \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\Part, WorkOrder, \Illuminate\Database\Eloquent\Relations\Pivot&object{quantity: mixed, unit_price: mixed}, 'pivot'> */

    $_discounted = $order->discountedParts();
    /** @psalm-check-type-exact $_discounted = \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\Part, WorkOrder, \Illuminate\Database\Eloquent\Relations\Pivot&object{quantity: mixed, unit_price: mixed, discount: mixed}, 'pivot'> */

    $_priced = $order->pricedParts();
    /** @psalm-check-type-exact $_priced = \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\Part, WorkOrder, \Illuminate\Database\Eloquent\Relations\Pivot&object{quantity: int}, 'pivot'> */
}

function custom_pivot_keeps_its_class(Mechanic $mechanic): void
{
    $_certified = $mechanic->certifiedSpecializations();
    /** @psalm-check-type-exact $_certified = \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\MechanicSpecialization, Mechanic, \App\Models\SpecializationPivot, 'pivot'> */
}
?>
--EXPECTF--
