--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;

function test_factory_returns_model(): Customer
{
    return factory(\App\Models\Customer::class)->create();
}

function test_factory_returns_model_with_explicit_count(): Customer
{
    return factory(\App\Models\Customer::class, 1)->create();
}

/** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Customer> **/
function test_factory_returns_collection()
{
    return factory(\App\Models\Customer::class, 2)->create();
}

function test_factory_with_times_1_returns_model(): Customer
{
    return factory(\App\Models\Customer::class)->times(1)->create();
}

/** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Customer> **/
function test_factory_with_times_2_returns_collection()
{
    return factory(\App\Models\Customer::class)->times(2)->create();
}
?>
--EXPECTF--
