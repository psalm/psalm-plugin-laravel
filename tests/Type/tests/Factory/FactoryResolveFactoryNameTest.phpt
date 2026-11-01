--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

// Laravel's docblock returns the 1-arg `Factory<TClass>`; the stub's `TCount` default fills the second template.
function resolve_factory_name_binds_both_templates(): string
{
    return Factory::resolveFactoryName(Customer::class);
}
?>
--EXPECTF--
