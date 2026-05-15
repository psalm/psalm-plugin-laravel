--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Issue #648 regression — Builder macros registered via `Builder::macro(...)`
 * resolve when called through the typed query chain on a Model.
 *
 * The macro `testBuilderMacro` is registered in tests/Type/macro-fixtures.php.
 * It returns `string`, so `Customer::query()->testBuilderMacro()` must narrow
 * to `string`. Because Builder is a direct Macroable owner, the pseudo-methods
 * land on Builder itself and any Builder<TModel> call site dispatches to them
 * via normal method resolution.
 */

function test_builder_macro_resolves_via_query(): string
{
    $_ = Customer::query()->testBuilderMacro();
    /** @psalm-check-type-exact $_ = string */
    return $_;
}

/**
 * @param Builder<Customer> $builder
 */
function test_builder_macro_resolves_on_explicit_builder_param(Builder $builder): string
{
    $_ = $builder->testBuilderMacro();
    /** @psalm-check-type-exact $_ = string */
    return $_;
}

/**
 * Issue #648 — relation chain dispatch. `Customer::vehicles()` returns
 * `HasMany<Vehicle, Customer>`, which forwards `__call` to its underlying Builder
 * via the plugin's MethodForwardingHandler. Builder's macro must resolve
 * through that chain.
 */
function test_builder_macro_resolves_through_relation_chain(Customer $customer): string
{
    $_ = $customer->vehicles()->testBuilderMacro();
    /** @psalm-check-type-exact $_ = string */
    return $_;
}
?>
--EXPECT--
