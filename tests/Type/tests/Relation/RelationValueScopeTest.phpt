--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A value-returning scope forwarded through a relation chain.
 *
 * On a relation chain Laravel's Builder::callScope still evaluates `$scope(...) ?? $this`, but
 * here `$this` is the wrapped query; Relation::__call then maps a result identical to the query
 * back to the Relation itself. So a scope whose body returns a value yields `value | Relation`,
 * while the builder/void/null result keeps the Relation type for fluent chaining.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1053
 */

/** Value scope on a HasMany chain: related-model | the Relation. */
function test_value_scope_on_hasmany(): void
{
    $r = (new Customer())->vehicles();
    $_r = $r->firstElectric();
    /** @psalm-check-type-exact $_r = Vehicle|HasMany<Vehicle, Customer> */
}

/** Regression: a builder/void scope on the same chain keeps the Relation type. */
function test_builder_scope_on_hasmany_unchanged(): void
{
    $r = (new Customer())->vehicles();
    $_r = $r->byMake('Toyota');
    /** @psalm-check-type-exact $_r = HasMany<Vehicle, Customer> */
}
?>
--EXPECTF--

