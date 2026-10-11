--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.20.0');
--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;

/**
 * Model::__call() forwards incrementEachQuietly()/decrementEachQuietly() from Laravel 13.20.0.
 * They run without events, so unlike incrementEach() they never return false (#1670).
 */

function test_increment_each_quietly(Customer $customer): void
{
    $_result = $customer->incrementEachQuietly(['login_count' => 1, 'view_count' => 5]);
    /** @psalm-check-type-exact $_result = int */
}

function test_decrement_each_quietly(Customer $customer): void
{
    $_result = $customer->decrementEachQuietly(['login_count' => 1], ['last_login' => now()]);
    /** @psalm-check-type-exact $_result = int */
}
?>
--EXPECTF--
