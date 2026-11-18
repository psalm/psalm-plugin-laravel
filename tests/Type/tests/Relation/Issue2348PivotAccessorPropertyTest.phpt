--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;
use App\Models\WorkOrder;

/**
 * A BelongsToMany / MorphToMany relation property carries the pivot under its accessor, `pivot` included, with the
 * relation's full pivot type (`using()` class, `withPivot()` columns) and the related model's custom collection
 * (#2348). Traces, not check-type: Psalm compares object shapes inside an intersection by name only.
 */
function pivot_relation_property_carries_the_accessor(Mechanic $mechanic, WorkOrder $order): void
{
    $_default = $mechanic->specializations;
    /** @psalm-trace $_default */

    $_accessor = $mechanic->specializationsAccessorOnly;
    /** @psalm-trace $_accessor */

    $_delegated = $mechanic->specializationProfiles;
    /** @psalm-trace $_delegated */

    $_untyped = $mechanic->specializationNotes;
    /** @psalm-trace $_untyped */

    $_using_with_pivot = $mechanic->certifiedSpecializations;
    /** @psalm-trace $_using_with_pivot */

    $_custom_collection = $order->parts;
    /** @psalm-trace $_custom_collection */

    $_morph_to_many = $mechanic->workOrderTagsAccessorOnly;
    /** @psalm-trace $_morph_to_many */

    $_morph_to_many_default = $mechanic->bookmarkedAdmins;
    /** @psalm-trace $_morph_to_many_default */

    $_details = $mechanic->specializationsAccessorOnly->firstOrFail()->details;
    /** @psalm-trace $_details */
}

/** An unknown accessor invents no key, and other collection relations are untouched. */
function property_without_a_provable_pivot_key(Mechanic $mechanic): void
{
    $_dynamic = $mechanic->specializationsDynamicAccessor;
    /** @psalm-trace $_dynamic */

    $_const = $mechanic->specializationsConstAccessor;
    /** @psalm-trace $_const */

    $_has_many = $mechanic->workOrders;
    /** @psalm-trace $_has_many */
}
?>
--EXPECTF--
Trace on line %d: $_default: Illuminate\Database\Eloquent\Collection<int, App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}>
Trace on line %d: $_accessor: Illuminate\Database\Eloquent\Collection<int, App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}>
Trace on line %d: $_delegated: Illuminate\Database\Eloquent\Collection<int, App\Models\MechanicSpecialization&object{profile:App\Models\SpecializationPivot}>
Trace on line %d: $_untyped: Illuminate\Database\Eloquent\Collection<int, App\Models\MechanicSpecialization&object{note:Illuminate\Database\Eloquent\Relations\Pivot&object{notes:mixed}}>
Trace on line %d: $_using_with_pivot: Illuminate\Database\Eloquent\Collection<int, App\Models\MechanicSpecialization&object{pivot:App\Models\SpecializationPivot&object{certified_at:null|string}}>
Trace on line %d: $_custom_collection: App\Collections\PartCollection<int, App\Models\Part&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot&object{quantity:mixed, unit_price:mixed}}>
Trace on line %d: $_morph_to_many: App\Collections\WorkOrderCollection<int, App\Models\WorkOrder&object{meta:Illuminate\Database\Eloquent\Relations\MorphPivot}>
Trace on line %d: $_morph_to_many_default: Illuminate\Database\Eloquent\Collection<int, App\Models\Admin&object{pivot:Illuminate\Database\Eloquent\Relations\MorphPivot}>
Trace on line %d: $_details: Illuminate\Database\Eloquent\Relations\Pivot
Trace on line %d: $_dynamic: Illuminate\Database\Eloquent\Collection<int, App\Models\MechanicSpecialization>
Trace on line %d: $_const: Illuminate\Database\Eloquent\Collection<int, App\Models\MechanicSpecialization>
Trace on line %d: $_has_many: App\Collections\WorkOrderCollection<int, App\Models\WorkOrder>
