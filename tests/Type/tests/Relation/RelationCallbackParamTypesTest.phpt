--FILE--
<?php declare(strict_types=1);

use App\Builders\VehicleBuilder;
use App\Builders\WorkOrderBuilder;
use App\Models\Customer;
use App\Models\Part;
use App\Models\Supplier;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;

/**
 * The closure-literal callback of an Eloquent relation-query method gets the RELATED model's builder
 * (custom builder when the related model has one), on all four receiver kinds. Params providers carry
 * no receiver, so these exercise the plugin's per-call-site resolution (`Customer::vehicles` →
 * VehicleBuilder, `Vehicle::workOrders` → WorkOrderBuilder, `Supplier::parts` → plain Builder).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1676
 */

// --- Receivers (whereHas, custom-builder slot) ---

function test_builder_receiver(): void
{
    Customer::query()->whereHas('vehicles', function ($q): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle> */
        $q->whereElectric();
    });
}

function test_static_receiver(): void
{
    Customer::whereHas('vehicles', function ($q): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle> */
        $q->whereElectric();
    });
}

function test_relation_receiver(Customer $customer): void
{
    $customer->vehicles()->whereHas('workOrders', function ($q): void {
        /** @psalm-check-type-exact $q = WorkOrderBuilder<App\Models\WorkOrder> */
        $q->whereCompleted();
    });
}

function test_custom_builder_receiver(): void
{
    Vehicle::query()->whereHas('workOrders', function ($q): void {
        /** @psalm-check-type-exact $q = WorkOrderBuilder<App\Models\WorkOrder> */
        $q->whereCompleted();
    });
}

function test_plain_builder_slot(): void
{
    Supplier::query()->whereHas('parts', function ($q): void {
        /** @psalm-check-type-exact $q = Builder<Part> */
        $q->where('name', 'x');
    });
}

function test_dot_path_applies_to_last_segment(): void
{
    Customer::query()->whereHas('vehicles.workOrders', function ($q): void {
        /** @psalm-check-type-exact $q = WorkOrderBuilder<App\Models\WorkOrder> */
        $q->whereCompleted();
    });
}

// --- Families and callback positions ---

function test_has_callback_is_fifth_argument(): void
{
    Customer::query()->has('vehicles', '>=', 1, 'and', function ($q): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle> */
        $q->whereElectric();
    });
}

function test_doesnt_have_callback_is_third_argument(): void
{
    Customer::query()->doesntHave('vehicles', 'and', function ($q): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle> */
        $q->whereElectric();
    });
}

function test_where_has_family(): void
{
    Customer::query()->orWhereHas('vehicles', function ($q): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle> */
        $q->whereElectric();
    });
    Customer::query()->whereDoesntHave('vehicles', function ($q): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle> */
        $q->whereElectric();
    });
    Customer::query()->orWhereDoesntHave('vehicles', function ($q): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle> */
        $q->whereElectric();
    });
}

/** `whereRelation(..., $column)` takes the callback in the `$column` slot. */
function test_where_relation_family(): void
{
    Customer::query()->whereRelation('vehicles', function ($q): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle> */
        $q->whereElectric();
    });
    Customer::query()->orWhereRelation('vehicles', function ($q): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle> */
        $q->whereElectric();
    });
    Customer::query()->whereDoesntHaveRelation('vehicles', function ($q): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle> */
        $q->whereElectric();
    });
    Customer::query()->orWhereDoesntHaveRelation('vehicles', function ($q): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle> */
        $q->whereElectric();
    });
}

/** A named `callback:` argument is bound by name, not by position. */
function test_named_callback_argument(): void
{
    Customer::query()->whereHas(callback: function ($q): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle> */
        $q->whereElectric();
    }, relation: 'vehicles');
}

/** A nullsafe receiver call is the same single-atomic receiver. */
function test_nullsafe_receiver(?Customer $customer): void
{
    $customer?->vehicles()->whereHas('workOrders', function ($q): void {
        /** @psalm-check-type-exact $q = WorkOrderBuilder<App\Models\WorkOrder> */
        $q->whereCompleted();
    });
}

/** A declared `Builder<Customer>` variable is the same single-atomic receiver. */
function test_declared_builder_receiver(Builder $builder): void
{
    /** @var Builder<Customer> $builder */
    $builder->whereHas('vehicles', function ($q): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle> */
        $q->whereElectric();
    });
}

/** Psalm re-analyzes loop bodies: the second pass must see the same slot, not an inferred overwrite. */
function test_loop_reanalysis_keeps_slot(int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        Customer::query()->whereHas('vehicles', function ($q): void {
            /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle> */
            $q->whereElectric();
        });
    }
}
?>
--EXPECTF--
