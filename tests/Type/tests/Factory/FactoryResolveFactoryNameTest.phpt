--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

// Laravel's own docblock returns the 1-arg `Factory<TClass>`, which MissingTemplateParam rejects at errorLevel 1.
function resolve_factory_name_binds_both_templates(): string
{
    return Factory::resolveFactoryName(Customer::class);
}
?>
--EXPECTF--
