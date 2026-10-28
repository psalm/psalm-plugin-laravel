--FILE--
<?php declare(strict_types=1);

use App\Builders\VehicleBuilder;
use App\Builders\WorkOrderBuilder;
use App\Models\Customer;
use App\Models\DamageReport;
use App\Models\Invoice;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;

/**
 * Morph callbacks run as `$callback($query, $type)` once per literal type, with the TYPE's builder
 * (Laravel wraps the call in `whereHas($belongsTo, ...)`): `$q` is the union of the literal types'
 * builders and `$type` the union of their class-strings. `'*'` and any non-literal `$types` keep the
 * stub (mixed). The `$relation` must be a direct MorphTo.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1676
 */

function test_where_has_morph_unions_the_literal_types(): void
{
    DamageReport::query()->whereHasMorph('reportable', [Vehicle::class, WorkOrder::class], function ($q, $type): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle>|WorkOrderBuilder<WorkOrder> */
        /** @psalm-check-type-exact $type = class-string<Vehicle>|class-string<WorkOrder> */
        $q->where('type', $type);
    });
}

/** A single type may be passed as a bare string; a morph-map alias (see macro-fixtures.php) resolves to its model. */
function test_single_string_type(): void
{
    DamageReport::query()->whereHasMorph('reportable', Vehicle::class, function ($q, $type): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle> */
        /** @psalm-check-type-exact $type = class-string<Vehicle> */
        $q->whereElectric()->where('type', $type);
    });
    DamageReport::query()->whereHasMorph('reportable', ['App\Models\WorkOrder'], function ($q): void {
        /** @psalm-check-type-exact $q = WorkOrderBuilder<WorkOrder> */
        $q->whereCompleted();
    });
    DamageReport::query()->whereHasMorph('reportable', ['damaged-vehicle', WorkOrder::class], function ($q, $type): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle>|WorkOrderBuilder<WorkOrder> */
        /** @psalm-check-type-exact $type = class-string<Vehicle>|class-string<WorkOrder> */
        $q->where('type', $type);
    });
}

function test_morph_family_and_positions(): void
{
    DamageReport::query()->orWhereHasMorph('reportable', [Vehicle::class], function ($q): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle> */
        $q->whereElectric();
    });
    DamageReport::query()->whereDoesntHaveMorph('reportable', [Vehicle::class], function ($q): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle> */
        $q->whereElectric();
    });
    DamageReport::query()->orWhereDoesntHaveMorph('reportable', [Vehicle::class], function ($q): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle> */
        $q->whereElectric();
    });
    DamageReport::query()->hasMorph('reportable', [Vehicle::class], '>=', 1, 'and', function ($q): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle> */
        $q->whereElectric();
    });
    DamageReport::query()->doesntHaveMorph('reportable', [Vehicle::class], 'and', function ($q): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle> */
        $q->whereElectric();
    });
}

/** `InvoiceBuilder` declares no generics of its own: the model comes from its `@extends Builder<Invoice>`. */
function test_non_generic_custom_builder_receiver(): void
{
    Invoice::query()->whereHasMorph('billable', [Customer::class, Supplier::class], function ($q, $type): void {
        /** @psalm-check-type-exact $q = Builder<Customer>|Builder<Supplier> */
        /** @psalm-check-type-exact $type = class-string<Customer>|class-string<Supplier> */
        $q->where('type', $type);
    });
}

function test_typed_params_accepted(): void
{
    DamageReport::query()->whereHasMorph(
        'reportable',
        [Vehicle::class, WorkOrder::class],
        fn (Builder $q, string $type): Builder => $q->where('type', $type),
    );
}
?>
--EXPECTF--
