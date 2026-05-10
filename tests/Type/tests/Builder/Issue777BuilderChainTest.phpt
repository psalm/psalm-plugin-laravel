--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/777.
 *
 * `Customer::query()->select(...)->leftJoin(...)->where(Closure)->groupBy(...)`
 * was reported (plugin 3.8.4 / Psalm 6.16.1) to infer as
 * `Builder<Eloquent>&static|Builder<Model>&static` instead of `Builder<Customer>`,
 * breaking concrete-class `@return Builder<Customer>` declarations. Likely root
 * cause: one of the chained methods (most plausibly `where(Closure)` whose
 * closure parameter is typed `Builder` without a template) drops `TModel`.
 *
 * Each function below carries a function-level `@return Builder<Customer>` (or
 * `Builder<Vehicle>`) — combined with the empty `--EXPECTF--` block, that is the
 * regression oracle. A regression that drops `TModel` mid-chain trips
 * `LessSpecificReturnStatement` / `InvalidReturnStatement` against the function
 * signature and the test fails.
 */

/** @return Builder<Customer> */
function issue777_builder_chain_keeps_tmodel_through_select_and_join(): Builder
{
    return Customer::query()
        ->select('customers.*')
        ->leftJoin('vehicles', 'vehicles.customer_id', '=', 'customers.id');
}

/** @return Builder<Customer> */
function issue777_builder_chain_keeps_tmodel_through_where_closure(): Builder
{
    return Customer::query()
        ->select('customers.*')
        ->leftJoin('vehicles', 'vehicles.customer_id', '=', 'customers.id')
        ->where(function (Builder $b): void { $b->where('email', 'x@example.com'); });
}

/** @return Builder<Customer> */
function issue777_builder_chain_keeps_tmodel_through_groupBy(): Builder
{
    return Customer::query()
        ->select('customers.*')
        ->leftJoin('vehicles', 'vehicles.customer_id', '=', 'customers.id')
        ->where(function (Builder $b): void { $b->where('email', 'x@example.com'); })
        ->groupBy('customers.id');
}

// Pterodactyl-style reproducer (issue's real-world example):
// User::accessibleServers() returns Builder<Server> after a 4-step chain.

/** @return Builder<Vehicle> */
function issue777_pterodactyl_style_query(Customer $customer): Builder
{
    return Vehicle::query()
        ->select('vehicles.*')
        ->leftJoin('customers', 'customers.id', '=', 'vehicles.customer_id')
        ->where(function (Builder $b) use ($customer): void {
            $b->where('customer_id', $customer->id);
        })
        ->groupBy('vehicles.id');
}
?>
--EXPECTF--
