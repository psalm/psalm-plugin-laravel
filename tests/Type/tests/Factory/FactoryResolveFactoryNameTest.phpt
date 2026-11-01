--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

// Laravel's docblock returns the 1-arg `Factory<TClass>`; the stub's `TCount` default fills the second template.
function resolve_factory_name_binds_both_templates(): string
{
    return Factory::resolveFactoryName(Customer::class);
}

// Intended widening: a `class-string<Factory<TClass>>` cannot rule out `new $class(3)`, so with the `TCount` default
// (`int|null`) `create()` answers the union, the same soundness rule as an unbound `@extends Factory<X>`.
// (`TClass` stays unbound in the printed type: Psalm does not bind it through the nested `class-string<Factory<...>>`.)
function resolve_factory_name_instantiated_factory_may_be_counted(): void
{
    $name = Factory::resolveFactoryName(Customer::class);
    $_made = (new $name())->create();
    /** @psalm-trace $_made */
}
?>
--EXPECTF--
UnsafeInstantiation on line %d: Cannot safely instantiate class Illuminate\Database\Eloquent\Factories\Factory with "new $class_name" as its constructor might change in child classes
Trace on line %d: $_made: (Illuminate\Database\Eloquent\Collection<int, TClass:fn-illuminate\database\eloquent\factories\factory::resolvefactoryname as Illuminate\Database\Eloquent\Model>)|(TClass:fn-illuminate\database\eloquent\factories\factory::resolvefactoryname as Illuminate\Database\Eloquent\Model)
