--FILE--
<?php declare(strict_types=1);

use App\Models\User;

function test_factory_returns_model(): User
{
    return factory(\App\Models\User::class)->create();
}

function test_factory_returns_model_with_explicit_count(): User
{
    return factory(\App\Models\User::class, 1)->create();
}

/** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> **/
function test_factory_returns_collection()
{
    return factory(\App\Models\User::class, 2)->create();
}

function test_factory_with_times_1_returns_model(): User
{
    return factory(\App\Models\User::class)->times(1)->create();
}

/** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> **/
function test_factory_with_times_2_returns_collection()
{
    return factory(\App\Models\User::class)->times(2)->create();
}
?>
--EXPECTF--
