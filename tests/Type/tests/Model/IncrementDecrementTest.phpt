--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;

/**
 * Model::increment() and decrement() are protected in Laravel's source but
 * explicitly forwarded as public via Model::__call(). The plugin redeclares
 * them as public in the Model stub so external callers don't get errors.
 *
 * increment()/decrement() return false when an `updating` listener cancels the update;
 * the quiet variants run without events and always return int (#1670).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/512
 */

function test_increment(Customer $customer): void
{
    $_result = $customer->increment('login_count');
    /** @psalm-check-type-exact $_result = false|int */
}

function test_decrement(Customer $customer): void
{
    $_result = $customer->decrement('login_count');
    /** @psalm-check-type-exact $_result = false|int */
}

function test_increment_with_amount(Customer $customer): void
{
    $_result = $customer->increment('login_count', 5);
    /** @psalm-check-type-exact $_result = false|int */
}

function test_increment_with_extra(Customer $customer): void
{
    $_result = $customer->increment('login_count', 1, ['last_login' => now()]);
    /** @psalm-check-type-exact $_result = false|int */
}

function test_increment_quietly(Customer $customer): void
{
    $_result = $customer->incrementQuietly('login_count');
    /** @psalm-check-type-exact $_result = int */
}

function test_decrement_quietly(Customer $customer): void
{
    $_result = $customer->decrementQuietly('login_count');
    /** @psalm-check-type-exact $_result = int */
}
?>
--EXPECTF--
