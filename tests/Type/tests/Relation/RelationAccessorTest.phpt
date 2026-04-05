--FILE--
<?php declare(strict_types=1);

use App\Collections\PartCollection;
use App\Collections\WorkOrderCollection;
use App\Models\Customer;
use App\Models\DamageReport;
use App\Models\Invoice;
use App\Models\Mechanic;
use App\Models\Part;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/497
 *
 * Test that relationship methods WITHOUT generic type annotations are
 * resolved as magic property accessors with correct types.
 */

// --- Single relations: ?RelatedModel ---

function test_belongsTo_without_generics(Shop $shop): ?Customer
{
    /** @psalm-check-type-exact $owner = Customer|null */
    $owner = $shop->owner;
    return $owner;
}

function test_hasOne_without_generics(Shop $shop): ?Invoice
{
    /** @psalm-check-type-exact $invoice = Invoice|null */
    $invoice = $shop->latestInvoice;
    return $invoice;
}

function test_morphOne_without_generics(Shop $shop): ?DamageReport
{
    /** @psalm-check-type-exact $report = DamageReport|null */
    $report = $shop->latestReport;
    return $report;
}

function test_belongsTo_chained_withDefault(Shop $shop): ?Customer
{
    /** @psalm-check-type-exact $customer = Customer|null */
    $customer = $shop->defaultCustomer;
    return $customer;
}

// --- Collection relations: custom collection types when registered (#645) ---

/** @return WorkOrderCollection<int, WorkOrder> */
function test_hasMany_custom_collection(Shop $shop): WorkOrderCollection
{
    /** @psalm-check-type-exact $workOrders = WorkOrderCollection<int, WorkOrder> */
    $workOrders = $shop->workOrders;
    return $workOrders;
}

/** @return PartCollection<int, Part> */
function test_belongsToMany_custom_collection(Shop $shop): PartCollection
{
    /** @psalm-check-type-exact $parts = PartCollection<int, Part> */
    $parts = $shop->parts;
    return $parts;
}

// Supplier has no custom collection — still returns default Collection
/** @return Collection<int, Supplier> */
function test_morphMany_without_generics(Shop $shop): Collection
{
    /** @psalm-check-type-exact $suppliers = Collection<int, Supplier> */
    $suppliers = $shop->suppliers;
    return $suppliers;
}

// Mechanic has no custom collection — still returns default Collection
/** @return Collection<int, Mechanic> */
function test_hasManyThrough_without_generics(Shop $shop): Collection
{
    /** @psalm-check-type-exact $mechanics = Collection<int, Mechanic> */
    $mechanics = $shop->mechanics;
    return $mechanics;
}

/** @return WorkOrderCollection<int, WorkOrder> */
function test_morphToMany_custom_collection(Shop $shop): WorkOrderCollection
{
    /** @psalm-check-type-exact $allWorkOrders = WorkOrderCollection<int, WorkOrder> */
    $allWorkOrders = $shop->allWorkOrders;
    return $allWorkOrders;
}

function test_hasOneThrough_without_generics(Shop $shop): ?Customer
{
    /** @psalm-check-type-exact $vehicleOwner = Customer|null */
    $vehicleOwner = $shop->vehicleOwner;
    return $vehicleOwner;
}

/** @return WorkOrderCollection<int, WorkOrder> */
function test_morphedByMany_custom_collection(Shop $shop): WorkOrderCollection
{
    /** @psalm-check-type-exact $workOrders = WorkOrderCollection<int, WorkOrder> */
    $workOrders = $shop->morphedWorkOrders;
    return $workOrders;
}

// --- MorphTo: ?Model (polymorphic, can't determine specific type) ---

function test_morphTo_without_generics(Shop $shop): ?Model
{
    /** @psalm-check-type-exact $shopable = Model|null */
    $shopable = $shop->shopable;
    return $shopable;
}

function test_morphTo_with_psalm_return_generics(Invoice $invoice): Customer|Supplier|null
{
    /** @psalm-check-type-exact $billable = Customer|Supplier|null */
    $billable = $invoice->billable;
    return $billable;
}

function test_morphTo_with_return_generics(Part $part): Supplier|WorkOrder|null
{
    /** @psalm-check-type-exact $orderedBy = Supplier|WorkOrder|null */
    $orderedBy = $part->orderedBy;
    return $orderedBy;
}

function test_morphTo_with_phpstan_return_generics(DamageReport $report): Vehicle|WorkOrder|null
{
    /** @psalm-check-type-exact $reportable = Vehicle|WorkOrder|null */
    $reportable = $report->reportable;
    return $reportable;
}

// --- No declared return type: inferred from method body ---

function test_no_return_type_morphOne(Customer $customer): ?DamageReport
{
    /** @psalm-check-type-exact $report = DamageReport|null */
    $report = $customer->latestReport;
    return $report;
}

// Supplier has no custom collection — default Collection
/** @return Collection<int, Supplier> */
function test_no_return_type_morphMany(Shop $shop): Collection
{
    /** @psalm-check-type-exact $suppliers = Collection<int, Supplier> */
    $suppliers = $shop->supplierList;
    return $suppliers;
}

// --- Existing behavior: generics still work ---

function test_belongsTo_with_generics(Invoice $invoice): ?WorkOrder
{
    /** @psalm-check-type-exact $workOrder = WorkOrder|null */
    $workOrder = $invoice->workOrder;
    return $workOrder;
}

/** @return Collection<int, Vehicle> */
function test_hasMany_with_generics(Customer $customer): Collection
{
    /** @psalm-check-type-exact $vehicles = Collection<int, Vehicle> */
    $vehicles = $customer->vehicles;
    return $vehicles;
}

// --- Generics + custom collection: WorkOrder has #[CollectedBy(WorkOrderCollection)] ---

/** @return WorkOrderCollection<int, WorkOrder> */
function test_morphToMany_with_generics_custom_collection(\App\Models\Admin $admin): WorkOrderCollection
{
    /** @psalm-check-type-exact $workOrders = WorkOrderCollection<int, WorkOrder> */
    $workOrders = $admin->workOrders;
    return $workOrders;
}
?>
--EXPECTF--
