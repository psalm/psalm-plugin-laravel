--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;

///** @return \Illuminate\Database\Eloquent\Collection<array-key, \App\Models\Customer> */
/*function test_scope(): \Illuminate\Database\Eloquent\Collection
{
    return Customer::active()->get();
}*/

function test_find_or_fail(): Customer
{
    return Customer::findOrFail(1);
}
?>
--EXPECTF--
