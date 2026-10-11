--FILE--
<?php declare(strict_types=1);

use App\Builders\VehicleBuilder;
use App\Models\Customer;
use App\Models\Part;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An explicitly typed callback param is checked against the related builder slot: the custom builder
 * and any wider Builder are accepted; a builder of another model is reported. Before the fix a custom
 * builder receiver (Vehicle::query()) coerced `fn (VehicleBuilder $q)` against `Builder<Model>`.
 *
 * withWhereHas()/withWhereRelation() reuse the closure as the eager-load constraint, which receives the
 * Relation: a Builder-only hint throws a TypeError at runtime and is reported here.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1676
 */

function test_custom_builder_hint_accepted(): void
{
    Customer::query()->whereHas('vehicles', fn (VehicleBuilder $q): VehicleBuilder => $q->whereElectric());
    Customer::whereHas('vehicles', fn (VehicleBuilder $q): VehicleBuilder => $q->whereElectric());
    Vehicle::query()->whereHas('workOrders', fn (\App\Builders\WorkOrderBuilder $q) => $q->whereCompleted());
}

function test_wider_builder_hint_accepted(): void
{
    Customer::query()->whereHas('vehicles', fn (Builder $q): Builder => $q->where('year', 2020));
    Vehicle::query()->whereHas('workOrders', fn (Builder $q): Builder => $q->where('status', 'pending'));
}

function test_unrelated_builder_hint_reported(): void
{
    Customer::query()->whereHas('vehicles', /** @param Builder<Part> $q */ fn (Builder $q): Builder => $q->where('name', 'x'));
}

function test_eager_load_closure_is_builder_or_relation(): void
{
    Customer::query()->withWhereHas('vehicles', function ($q): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle>|HasMany<Vehicle, Customer> */
        $q->where('year', 2020);
    });
    Customer::query()->withWhereRelation('vehicles', function ($q): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle>|HasMany<Vehicle, Customer> */
        $q->where('year', 2020);
    });
}

/** The `:col,col` select suffix is stripped before the relation is resolved. */
function test_with_where_has_strips_column_suffix(): void
{
    Customer::query()->withWhereHas('vehicles:id,make', function ($q): void {
        /** @psalm-check-type-exact $q = VehicleBuilder<Vehicle>|HasMany<Vehicle, Customer> */
        $q->where('year', 2020);
    });
}

function test_eager_load_builder_only_hint_reported(): void
{
    Customer::query()->withWhereHas('vehicles', fn (VehicleBuilder $q) => $q->whereElectric());
}

function test_eager_load_union_hint_accepted(): void
{
    Customer::query()->withWhereHas('vehicles', fn (VehicleBuilder|HasMany $q) => $q->where('year', 2020));
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 2 of Illuminate\Database\Eloquent\Builder::whereHas expects Closure[impure](App\Builders\VehicleBuilder<App\Models\Vehicle>):mixed, but Closure[impure](Illuminate\Database\Eloquent\Builder<App\Models\Part>):Illuminate\Database\Eloquent\Builder<App\Models\Part>&static provided
InvalidArgument on line %d: Argument 2 of Illuminate\Database\Eloquent\Builder::withWhereHas expects Closure[impure](App\Builders\VehicleBuilder<App\Models\Vehicle>|Illuminate\Database\Eloquent\Relations\HasMany<App\Models\Vehicle, App\Models\Customer>):mixed, but Closure[impure](App\Builders\VehicleBuilder):App\Builders\VehicleBuilder<Illuminate\Database\Eloquent\Model> provided
