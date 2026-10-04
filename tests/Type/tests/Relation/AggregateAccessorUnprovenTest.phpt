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

function test_relation_not_on_final_model_is_not_proof(Shop $shop): void
{
    $shop->loadCount('vehicles');
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
