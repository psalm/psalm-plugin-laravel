--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Vehicle;
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
 * Modern #[Scope] attribute: calling via the builder instance returns mixed.
 *
 * Known limitation: #[Scope] and legacy scopeXxx() methods on base-Builder models
 * return mixed when called on builder instances. Psalm routes through Builder::__call,
 * and BuilderScopeHandler cannot safely provide a return type here without also providing
 * params (which require knowing the model class — unavailable in the params provider event).
 * Custom-builder models don't have this limitation (CustomBuilderMethodHandler handles them).
 */
/** @return Builder<Customer> */
function test_scope_attribute_via_builder(): Builder
{
    return Customer::query()->verified();
}

/**
 * Modern #[Scope] attribute called statically: works at runtime via __callStatic -> query() -> Builder.
 * ScopeStaticCallHandler suppresses the InvalidStaticInvocation false positive.
 * Only protected #[Scope] methods are suppressed — public ones cause PHP Fatal Error in PHP 8.0+.
 */
function test_scope_attribute_static(): void
{
    Customer::verified();
}

/**
 * Negative: public non-scope method on a Model called statically must still report InvalidStaticInvocation.
 * Confirms ScopeStaticCallHandler does not over-suppress.
 */
function test_non_scope_static_invocation_not_suppressed(): void
{
    Customer::getFirstNameUsingLegacyAccessorAttribute();
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

// -----------------------------------------------------------------------
// SoftDeletes on base Builder instances (issue #635).
// Customer uses SoftDeletes with base Builder — trait @method static methods
// must also resolve when called on Builder instances (e.g., Customer::query()->withTrashed()).
// At runtime, SoftDeletingScope::extend() registers these as Builder macros.
// See https://github.com/psalm/psalm-plugin-laravel/issues/635
// -----------------------------------------------------------------------

/** SoftDeletes withTrashed on base Builder instance returns Builder<Customer>. */
function test_soft_deletes_with_trashed_via_query(): void
{
    $_result = Customer::query()->withTrashed();
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/** SoftDeletes onlyTrashed on base Builder instance returns Builder<Customer>. */
function test_soft_deletes_only_trashed_via_query(): void
{
    $_result = Customer::query()->onlyTrashed();
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/** SoftDeletes withoutTrashed on base Builder instance returns Builder<Customer>. */
function test_soft_deletes_without_trashed_via_query(): void
{
    $_result = Customer::query()->withoutTrashed();
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/** Chaining: withTrashed -> where -> get returns the correct collection type. */
function test_soft_deletes_with_trashed_chain_to_get(): void
{
    $_result = Customer::query()->withTrashed()->where('active', true)->get();
    /** @psalm-check-type-exact $_result = Collection<int, Customer> */
}

/** withTrashed accepts the optional bool argument without TooManyArguments. */
function test_soft_deletes_with_trashed_bool_arg(): void
{
    $_result = Customer::query()->withTrashed(false);
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/**
 * Negative test: model without SoftDeletes must NOT resolve withTrashed on its builder.
 *
 * isTraitBuilderMethod guards against false positives by checking the specific model's
 * pseudo_static_methods — Vehicle has no SoftDeletes, so withTrashed remains unresolved
 * even though Customer's registration has populated $baseBuilderTraitMethods.
 */
/** @return Builder<Vehicle> */
function test_non_soft_deletes_query_with_trashed(): Builder
{
    return Vehicle::query()->withTrashed();
}

/** Negative test: non-existent methods must still be reported. */
function test_nonexistent_method(): void
{
    $_result = Customer::completelyFakeMethod();
}
?>
--EXPECTF--
MixedReturnStatement on line %d: Could not infer a return type
InvalidStaticInvocation on line %d: Method App\Models\Customer::getFirstNameUsingLegacyAccessorAttribute is not static, but is called statically
MixedReturnStatement on line %d: Could not infer a return type
UndefinedMagicMethod on line %d: Magic method App\Builders\VehicleBuilder::withtrashed does not exist
UndefinedMagicMethod on line %d: Magic method App\Models\Customer::completelyfakemethod does not exist
