--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Shop;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1623
 *
 * Counterpart of AggregateAccessorProvenTest.phpt: whenever the code does not PROVE the aggregate
 * was loaded, the accessor stays `int|null` / `bool|null` (the handler declines).
 */

function test_unproven_read(Shop $shop): void
{
    /** @psalm-check-type-exact $count = int|null */
    $count = $shop->work_orders_count;
    echo $count;
}

function test_dynamic_relation_argument_is_not_proof(Shop $shop, string $relation): void
{
    $shop->loadCount($relation);
    /** @psalm-check-type-exact $count = int|null */
    $count = $shop->work_orders_count;
    echo $count;
}

function test_dynamic_array_entry_is_not_proof(Shop $shop, string $relation): void
{
    $shop->loadCount([$relation, 'parts']);
    /** @psalm-check-type-exact $count = int|null */
    $count = $shop->work_orders_count;
    echo $count;
}

function test_select_after_with_count_drops_the_aggregate(): void
{
    $shop = Shop::withCount('workOrders')->select('id')->firstOrFail();
    /** @psalm-check-type-exact $count = int|null */
    $count = $shop->work_orders_count;
    echo $count;
}

function test_relation_not_on_final_model_is_not_proof(Shop $shop): mixed
{
    // Shop has no `vehicles` relation, so neither the conventional name nor the alias may become a fact.
    $shop->loadCount(['vehicles', 'vehicles as vehicle_total']);

    return [$shop->vehicles_count, $shop->vehicle_total];
}

function test_nullable_terminal_records_no_fact(): void
{
    $shop = Shop::withCount('workOrders')->first();
    /** @psalm-check-type-exact $count = int|null */
    $count = $shop->work_orders_count;
    echo $count;
}

function test_nullable_find_records_no_fact(): void
{
    $shop = Shop::withCount('workOrders')->find(1);
    /** @psalm-check-type-exact $count = int|null */
    $count = $shop->work_orders_count;
    echo $count;
}

function test_refresh_keeps_the_type_of_a_real_model_property(Shop $shop): void
{
    // `exists` is Model's own bool property, not an aggregate fact: refresh() must not widen it.
    $before = $shop->exists;
    $shop->refresh();
    /** @psalm-check-type-exact $after = bool */
    $after = $shop->exists;
    echo $before, $after;
}

function test_exists_then_count_alias_collision_declines(): mixed
{
    // withExists() installs a bool cast on the alias that outlives the later count: no fact.
    $model = Shop::withExists('parts as total')->withCount('workOrders as total')->firstOrFail();

    return $model->total;
}

function test_count_then_exists_alias_collision_declines(): mixed
{
    $model = Shop::withCount('workOrders as total')->withExists('parts as total')->firstOrFail();

    return $model->total;
}

function test_refresh_after_a_load_chain_drops_the_fact(Shop $shop): void
{
    $shop->loadCount('workOrders')->refresh();
    /** @psalm-check-type-exact $count = int|null */
    $count = $shop->work_orders_count;
    echo $count;
}

function test_reassigned_variable_loses_the_proof(Shop $shop): void
{
    $shop->loadCount('workOrders');
    $shop = Shop::query()->firstOrFail();
    /** @psalm-check-type-exact $count = int|null */
    $count = $shop->work_orders_count;
    echo $count;
}

function test_refresh_drops_loaded_aggregates(Shop $shop): void
{
    $shop->loadCount('workOrders');
    $shop->refresh();
    /** @psalm-check-type-exact $count = int|null */
    $count = $shop->work_orders_count;
    echo $count;
}

function test_first_or_new_terminal_is_not_proof(): void
{
    $shop = Shop::withCount('workOrders')->firstOrNew(['id' => 1]);
    /** @psalm-check-type-exact $count = int|null */
    $count = $shop->work_orders_count;
    echo $count;
}

function test_collection_hop_is_not_proof(): void
{
    $shop = Shop::withCount('workOrders')->get()->first();
    if ($shop === null) {
        return;
    }

    /** @psalm-check-type-exact $count = int|null */
    $count = $shop->work_orders_count;
    echo $count;
}

function test_variable_held_builder_is_not_proof(): void
{
    $query = Shop::withCount('workOrders');
    $shop = $query->firstOrFail();
    /** @psalm-check-type-exact $count = int|null */
    $count = $shop->work_orders_count;
    echo $count;
}

function test_default_load_does_not_leak_across_relations(Shop $shop): void
{
    /** @psalm-check-type-exact $count = int|null */
    $count = $shop->parts_count;
    echo $count;
}

function test_user_property_wins_over_a_proven_load(Customer $customer): void
{
    $customer->loadCount('workOrders');
    /** @psalm-check-type-exact $count = int<0, 10> */
    $count = $customer->work_orders_count;
    echo $count;
}
?>
--EXPECTF--
UndefinedMagicPropertyFetch on line %d: Magic instance property App\Models\Shop::$vehicles_count is not defined
UndefinedMagicPropertyFetch on line %d: Magic instance property App\Models\Shop::$vehicle_total is not defined
PossiblyNullPropertyFetch on line %d: Cannot get property on possibly null variable $shop of type App\Models\Shop|null
PossiblyNullPropertyFetch on line %d: Cannot get property on possibly null variable $shop of type App\Models\Shop|null
UndefinedMagicPropertyFetch on line %d: Magic instance property App\Models\Shop::$total is not defined
UndefinedMagicPropertyFetch on line %d: Magic instance property App\Models\Shop::$total is not defined
