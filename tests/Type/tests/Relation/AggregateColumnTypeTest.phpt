--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Shop;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1623
 *
 * withMin/withMax/withSum/withAvg accessors are typed from the aggregated column's RAW schema type
 * (never casts or `@property`). The type-test app has no migrations, so no column resolves here and
 * every accessor takes its unresolvable-column fallback. The per-column cells (int, float, string,
 * unsigned int) are covered by ModelAggregatePropertyHandlerTest::columnAwareType_maps_raw_column_type().
 */

function test_min_unresolvable_column_keeps_string_fallback(Shop $shop): void
{
    /** @psalm-check-type-exact $min = string|null */
    $min = $shop->work_orders_min_price;
    echo $min;
}

function test_max_unresolvable_column_keeps_string_fallback(Shop $shop): void
{
    /** @psalm-check-type-exact $max = string|null */
    $max = $shop->work_orders_max_price;
    echo $max;
}

function test_sum_unresolvable_column_admits_every_driver_result(Shop $shop): void
{
    /** @psalm-check-type-exact $sum = float|int|numeric-string|null */
    $sum = $shop->work_orders_sum_amount;
    echo $sum;
}

function test_avg_is_never_a_plain_float(Shop $shop): void
{
    /** @psalm-check-type-exact $avg = float|numeric-string|null */
    $avg = $shop->work_orders_avg_rating;
    echo $avg;
}

function test_polymorphic_relation_has_no_related_model(Shop $shop): void
{
    // MorphTo: the related model is not statically known, so the column cannot be resolved.
    /** @psalm-check-type-exact $sum = float|int|numeric-string|null */
    $sum = $shop->shopable_sum_amount;
    echo $sum;
}

function test_proven_alias_sum_stays_nullable_and_unresolved(Shop $shop): void
{
    $shop->loadMax('workOrders as latest_price', 'price');
    /** @psalm-check-type-exact $max = string|null */
    $max = $shop->latest_price;
    echo $max;
}

function test_user_property_wins_over_column_aware_type(Customer $customer): void
{
    /** @psalm-check-type-exact $max = int|null */
    $max = $customer->vehicles_max_year;
    echo $max;
}

function test_user_property_wins_over_proven_column_aware_load(Customer $customer): void
{
    $customer->loadMax('vehicles', 'year');
    /** @psalm-check-type-exact $max = int|null */
    $max = $customer->vehicles_max_year;
    echo $max;
}
?>
--EXPECTF--
