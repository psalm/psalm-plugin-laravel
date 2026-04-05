--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Static calls to Query\Builder methods via Model::__callStatic should resolve
 * without UndefinedMagicMethod. These methods live on Query\Builder and are
 * forwarded through Eloquent\Builder at runtime via __call -> forwardCallTo.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/498
 */

/** Query\Builder method forwarded through double mixin: Model -> Builder -> Query\Builder. */
function test_static_whereIn(): void
{
    $_result = Customer::whereIn('id', [1, 2, 3]);
    /** @psalm-check-type-exact $_result = Builder<Customer>&static */
}

function test_static_orderBy(): void
{
    $_result = Customer::orderBy('name');
    /** @psalm-check-type-exact $_result = Builder<Customer>&static */
}

/** Chaining: forwarded method -> Eloquent\Builder method -> terminal. */
function test_static_whereIn_chain_to_get(): void
{
    $_result = Customer::whereIn('id', [1, 2, 3])->get();
    /** @psalm-check-type-exact $_result = Collection<int, Customer> */
}

/** Chaining two forwarded Query\Builder methods. */
function test_static_chain_forwarded_methods(): void
{
    $_result = Customer::whereIn('id', [1, 2, 3])->orderBy('name')->get();
    /** @psalm-check-type-exact $_result = Collection<int, Customer> */
}

/** Regression: Customer::where() must still work (declared on Eloquent\Builder stub). */
/** @return Builder<Customer> */
function test_static_where(): Builder
{
    return Customer::where('active', true);
}

/** Regression: Customer::query() must still work. */
function test_static_query(): void
{
    $_result = Customer::query();
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/** Legacy scope called statically: scopeActive -> Customer::active(). */
function test_static_legacy_scope(): void
{
    $_result = Customer::active();
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/**
 * Modern #[Scope] attribute: the idiomatic call is through the Builder.
 * This exercises BuilderScopeHandler::hasScopeAttribute via the Builder path.
 *
 * Calling Customer::verified() statically triggers InvalidStaticInvocation because
 * it's a real instance method — see test_scope_attribute_static_is_invalid below.
 */
/** @return Builder<Customer> */
function test_scope_attribute_via_builder(): Builder
{
    return Customer::query()->verified();
}

/**
 * Known limitation: #[Scope] methods work at runtime via __callStatic -> query() -> Builder,
 * but Psalm sees them as real instance methods and reports InvalidStaticInvocation.
 */
function test_scope_attribute_static_is_invalid(): void
{
    $_result = Customer::verified();
}

// -----------------------------------------------------------------------
// SoftDeletes on standard builder (no custom builder).
// Regression: Customer uses SoftDeletes with base Builder — trait @method static
// must continue to resolve via Psalm's native pseudo_static_methods.
// See https://github.com/psalm/psalm-plugin-laravel/issues/631
// -----------------------------------------------------------------------

/** SoftDeletes withTrashed on standard builder returns Builder<Customer&static>. */
function test_soft_deletes_with_trashed_standard_builder(): void
{
    $_result = Customer::withTrashed();
    /** @psalm-check-type-exact $_result = Builder<Customer&static> */
}

/** SoftDeletes onlyTrashed on standard builder returns Builder<Customer&static>. */
function test_soft_deletes_only_trashed_standard_builder(): void
{
    $_result = Customer::onlyTrashed();
    /** @psalm-check-type-exact $_result = Builder<Customer&static> */
}

/** Negative test: non-existent methods must still be reported. */
function test_nonexistent_method(): void
{
    $_result = Customer::completelyFakeMethod();
}
?>
--EXPECTF--
MixedReturnStatement on line %d: Could not infer a return type
InvalidStaticInvocation on line %d: Method App\Models\Customer::verified is not static, but is called statically
UndefinedMagicMethod on line %d: Magic method App\Models\Customer::completelyfakemethod does not exist
