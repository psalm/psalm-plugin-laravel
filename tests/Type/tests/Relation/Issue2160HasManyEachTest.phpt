--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Vehicle;

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/2160.
 *
 * each() on a HasMany relation (directly or after a forwarded call) must check the callback against the
 * related model, not against Builder<Model>.
 */

function has_many_each_accepts_related_model_callback(Customer $customer): void
{
    $customer->vehicles()->each(function (Vehicle $vehicle): void {
        $vehicle->getKey();
    });
}

function has_many_each_after_forwarded_call(Customer $customer): void
{
    $customer->vehicles()->whereIn('id', [1])->each(function (Vehicle $vehicle): void {
        $vehicle->getKey();
    });
}
?>
--EXPECTF--
