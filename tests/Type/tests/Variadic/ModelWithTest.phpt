--FILE--
<?php declare(strict_types=1);

namespace App;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Model::with() is a static method that accepts variadic relation names via
 * func_get_args() when the first argument is a string. Single array form is also
 * supported.
 */
function model_with_single_string(): void
{
    $_result = Customer::with('primaryVehicle');
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

function model_with_variadic_strings(): void
{
    $_result = Customer::with('primaryVehicle', 'vehicles');
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

function model_with_array_form(): void
{
    $_result = Customer::with(['primaryVehicle', 'vehicles']);
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}
?>
--EXPECTF--
