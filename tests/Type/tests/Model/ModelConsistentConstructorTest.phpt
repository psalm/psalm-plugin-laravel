--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Database\Eloquent\Model;

/** @param class-string<Model> $modelClass */
function _instantiate(string $modelClass): Model
{
    return new $modelClass();
}

/** @param class-string<\App\Models\Customer> $customerClass */
function _instantiateCustomer(string $customerClass): \App\Models\Customer
{
    return new $customerClass(['name' => 'x']);
}
?>
--EXPECTF--
