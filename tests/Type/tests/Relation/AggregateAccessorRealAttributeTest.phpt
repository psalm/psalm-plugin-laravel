--FILE--
<?php declare(strict_types=1);

use App\Models\Shop;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1623
 *
 * A real model attribute named like an aggregate accessor shadows it. Precedence:
 * `@property`, column, cast, accessor, then aggregate. Shop::mechanicsCount() is an `Attribute`
 * accessor and Shop::getVehicleOwnerExistsAttribute() a legacy one; `mechanics` and `vehicleOwner`
 * are relations, so `mechanics_count` / `vehicle_owner_exists` would otherwise be aggregates.
 * Column and cast keys are covered by ModelAggregatePropertyHandlerTest (the type-test app has
 * no migrations).
 */

function test_attribute_accessor_shadows_count_aggregate(Shop $shop): void
{
    /** @psalm-check-type-exact $value = string */
    $value = $shop->mechanics_count;
    echo $value;
}

function test_legacy_accessor_shadows_exists_aggregate(Shop $shop): void
{
    /** @psalm-check-type-exact $value = string */
    $value = $shop->vehicle_owner_exists;
    echo $value;
}

function test_unrelated_count_is_still_an_aggregate(Shop $shop): void
{
    /** @psalm-check-type-exact $value = int|null */
    $value = $shop->suppliers_count;
    echo $value;
}

function test_proven_load_still_wins_over_the_accessor_type(Shop $shop): void
{
    $shop->loadCount('mechanics');
    /** @psalm-check-type-exact $value = int<0, max> */
    $value = $shop->mechanics_count;
    echo $value;
}
?>
--EXPECTF--
