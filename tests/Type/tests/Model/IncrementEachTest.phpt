--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.3.0');
--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;

/**
 * Model::__call() forwards incrementEach()/decrementEach() only from Laravel 13.3.0, and they
 * return false when an `updating` listener cancels the update (#1670).
 */

function test_increment_each(Customer $customer): void
{
    $_result = $customer->incrementEach(['login_count' => 1, 'view_count' => 5]);
    /** @psalm-check-type-exact $_result = false|int */
}

function test_decrement_each(Customer $customer): void
{
    $_result = $customer->decrementEach(['login_count' => 1]);
    /** @psalm-check-type-exact $_result = false|int */
}

function test_increment_each_with_extra(Customer $customer): void
{
    $_result = $customer->incrementEach(['login_count' => 1], ['last_login' => now()]);
    /** @psalm-check-type-exact $_result = false|int */
}

function test_decrement_each_with_extra(Customer $customer): void
{
    $_result = $customer->decrementEach(['login_count' => 1], ['last_login' => now()]);
    /** @psalm-check-type-exact $_result = false|int */
}
?>
--EXPECTF--
