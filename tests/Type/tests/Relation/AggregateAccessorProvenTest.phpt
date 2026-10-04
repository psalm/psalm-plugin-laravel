--FILE--
<?php declare(strict_types=1);

use App\Models\Shop;
use Illuminate\Database\Eloquent\Builder;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1623
 *
 * `{relation}_count` / `{relation}_exists` are `int|null` / `bool|null` when nothing proves the
 * aggregate was loaded. When the code proves it (model `$withCount` default, in-place
 * `loadCount()` on a variable, an assigned or directly-fetched query chain) the type is precise.
 * Negative counterparts live in AggregateAccessorUnprovenTest.phpt.
 */

// --- 1. Model $withCount defaults (Shop::$withCount = ['artists', 'suppliers as supplier_total']) ---

function test_with_count_default_conventional_name(Shop $shop): void
{
    /** @psalm-check-type-exact $count = int<0, max> */
    $count = $shop->artists_count;
    echo $count;
}

function test_with_count_default_alias_entry(Shop $shop): void
{
    /** @psalm-check-type-exact $count = int<0, max> */
    $count = $shop->supplier_total;
    echo $count;
}

// --- 2. In-place load on a variable ---

function test_load_count_in_place(Shop $shop): void
{
    $shop->loadCount('workOrders');
    /** @psalm-check-type-exact $count = int<0, max> */
    $count = $shop->work_orders_count;
    echo $count;
}

function test_load_count_variadic(Shop $shop): void
{
    $shop->loadCount('workOrders', 'parts');
    /** @psalm-check-type-exact $orders = int<0, max> */
    $orders = $shop->work_orders_count;
    /** @psalm-check-type-exact $parts = int<0, max> */
    $parts = $shop->parts_count;
    echo $orders, $parts;
}

function test_load_count_list(Shop $shop): void
{
    $shop->loadCount(['workOrders', 'parts']);
    /** @psalm-check-type-exact $parts = int<0, max> */
    $parts = $shop->parts_count;
    echo $parts;
}

function test_load_count_keyed_closure(Shop $shop): void
{
    $shop->loadCount(['workOrders' => static fn (Builder $query): Builder => $query]);
    /** @psalm-check-type-exact $count = int<0, max> */
    $count = $shop->work_orders_count;
    echo $count;
}

function test_load_exists_in_place(Shop $shop): void
{
    $shop->loadExists('workOrders');
    /** @psalm-check-type-exact $exists = bool */
    $exists = $shop->work_orders_exists;
    echo $exists;
}

function test_load_count_alias(Shop $shop): void
{
    $shop->loadCount('workOrders as total_orders');
    /** @psalm-check-type-exact $count = int<0, max> */
    $count = $shop->total_orders;
    echo $count;
}

function test_load_count_alias_is_case_insensitive(Shop $shop): void
{
    $shop->loadCount('workOrders AS total_orders');
    /** @psalm-check-type-exact $count = int<0, max> */
    $count = $shop->total_orders;
    echo $count;
}

function test_load_sum_alias(Shop $shop): void
{
    $shop->loadSum('workOrders as total_price', 'price');
    /** @psalm-check-type-exact $sum = float|int|numeric-string|null */
    $sum = $shop->total_price;
    echo $sum;
}

function test_chained_loads_compose(Shop $shop): void
{
    $shop->loadCount('workOrders')->loadExists('parts');
    /** @psalm-check-type-exact $count = int<0, max> */
    $count = $shop->work_orders_count;
    /** @psalm-check-type-exact $exists = bool */
    $exists = $shop->parts_exists;
    echo $count, $exists;
}

function test_load_count_snake_case_relation(Shop $shop): void
{
    $shop->loadCount('damage_reports');
    /** @psalm-check-type-exact $count = int<0, max> */
    $count = $shop->damage_reports_count;
    echo $count;
}

// --- 3. Assignment from a query chain ---

function test_assigned_chain_first_or_fail(): void
{
    $shop = Shop::query()->withCount('workOrders')->where('id', 1)->firstOrFail();
    /** @psalm-check-type-exact $count = int<0, max> */
    $count = $shop->work_orders_count;
    echo $count;
}

function test_assigned_chain_static_with_exists(): void
{
    $shop = Shop::withExists('workOrders')->findOrFail(1);
    /** @psalm-check-type-exact $exists = bool */
    $exists = $shop->work_orders_exists;
    echo $exists;
}

function test_assigned_chain_nullable_first(): void
{
    $shop = Shop::withCount('workOrders')->first();
    if ($shop === null) {
        return;
    }

    /** @psalm-check-type-exact $count = int<0, max> */
    $count = $shop->work_orders_count;
    echo $count;
}

function test_assigned_chain_alias(): void
{
    $shop = Shop::withCount('workOrders as total_orders')->firstOrFail();
    /** @psalm-check-type-exact $count = int<0, max> */
    $count = $shop->total_orders;
    echo $count;
}

function test_assigned_chain_with_sum_alias(): void
{
    $shop = Shop::query()->withSum('workOrders as total_price', 'price')->sole();
    /** @psalm-check-type-exact $sum = float|int|numeric-string|null */
    $sum = $shop->total_price;
    echo $sum;
}

function test_assigned_chain_through_relation(Shop $shop): void
{
    $order = $shop->workOrders()->withCount('parts')->where('status', 'open')->firstOrFail();
    /** @psalm-check-type-exact $count = int<0, max> */
    $count = $order->parts_count;
    echo $count;
}

// --- 4. Direct chain fetch ---

function test_direct_chain_fetch(): void
{
    /** @psalm-check-type-exact $count = int<0, max> */
    $count = Shop::withCount('workOrders')->firstOrFail()->work_orders_count;
    echo $count;
}

function test_direct_load_chain_fetch(Shop $shop): void
{
    /** @psalm-check-type-exact $count = int<0, max> */
    $count = $shop->loadCount('workOrders')->work_orders_count;
    echo $count;
}

function test_direct_load_exists_chain_fetch(Shop $shop): void
{
    /** @psalm-check-type-exact $exists = bool */
    $exists = $shop->loadExists('workOrders')->work_orders_exists;
    echo $exists;
}

// --- #1623 reproducer stays clean: the left side of `??` is unproven, the right side proven ---

function test_issue_1623_null_coalesce_reproducer(Shop $shop): void
{
    /** @psalm-check-type-exact $count = int */
    $count = $shop->work_orders_count ?? $shop->loadCount('workOrders')->work_orders_count;
    echo $count;
}
?>
--EXPECTF--
