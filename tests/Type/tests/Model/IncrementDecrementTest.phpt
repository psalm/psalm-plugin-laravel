--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;

/**
 * Model::increment() and decrement() are protected in Laravel's source but
 * explicitly forwarded as public via Model::__call(). The plugin redeclares
 * them as public in the Model stub so external callers don't get errors.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/512
 */

function test_increment(Customer $customer): void
{
    $_result = $customer->increment('login_count');
    /** @psalm-check-type-exact $_result = int */
}

function test_decrement(Customer $customer): void
{
    $_result = $customer->decrement('login_count');
    /** @psalm-check-type-exact $_result = int */
}

function test_increment_with_amount(Customer $customer): void
{
    $_result = $customer->increment('login_count', 5);
    /** @psalm-check-type-exact $_result = int */
}

function test_increment_with_extra(Customer $customer): void
{
    $_result = $customer->increment('login_count', 1, ['last_login' => now()]);
    /** @psalm-check-type-exact $_result = int */
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

function test_increment_each(Customer $customer): void
{
    $_result = $customer->incrementEach(['login_count' => 1, 'view_count' => 5]);
    /** @psalm-check-type-exact $_result = int */
}

function test_decrement_each(Customer $customer): void
{
    $_result = $customer->decrementEach(['login_count' => 1]);
    /** @psalm-check-type-exact $_result = int */
}

function test_increment_each_with_extra(Customer $customer): void
{
    $_result = $customer->incrementEach(['login_count' => 1], ['last_login' => now()]);
    /** @psalm-check-type-exact $_result = int */
}

function test_decrement_each_with_extra(Customer $customer): void
{
    $_result = $customer->decrementEach(['login_count' => 1], ['last_login' => now()]);
    /** @psalm-check-type-exact $_result = int */
}
?>
--EXPECT--
