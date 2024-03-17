--FILE--
<?php declare(strict_types=1);

use App\Models\User;

///** @return \Illuminate\Database\Eloquent\Collection<array-key, \App\Models\User> */
/*function test_scope(): \Illuminate\Database\Eloquent\Collection
{
    return User::active()->get();
}*/

function test_find_or_fail(): User
{
    return User::findOrFail(1);
}
?>
--EXPECTF--
