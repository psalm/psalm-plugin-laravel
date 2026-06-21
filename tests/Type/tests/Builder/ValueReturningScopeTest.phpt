--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// Skip on Laravel < 12: this test asserts #[Scope]-attributed scope resolution.
// The #[Scope] attribute is Laravel 12+, so on Laravel 11 the plugin correctly does
// not resolve such methods as scopes (see EloquentModelMethods::hasScopeAttribute).
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('12.0.0');
--FILE--
<?php declare(strict_types=1);

use App\Builders\VehicleBuilder;
use App\Models\AbstractDocument;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;

/**
 * A local scope is NOT required to return the builder. Laravel's Builder::callScope evaluates
 * `$result = $scope(...) ?? $this` and returns $result, so a scope whose body `return`s a value
 * (e.g. ->first(), a count) propagates that value to the caller; only a null/void/builder result
 * falls back to $this (the builder for these instance and static call forms).
 *
 * The plugin used to type EVERY forwarded scope call as Builder<Model>, ignoring the scope's
 * declared return. These assert the corrected `?? $this` coalesce.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1053
 */

/** Nullable value scope on a builder instance: self | Builder<self>, never null. */
function test_nullable_value_scope_instance(): void
{
    $_r = Customer::query()->firstActive();
    /** @psalm-check-type-exact $_r = Customer|Builder<Customer> */
}

/**
 * The `?? $this` swap means the result is never null, so a downstream `=== null` check is
 * correctly reported redundant — matching the runtime, where the scope's null result became
 * the builder rather than null.
 */
function test_nullable_value_scope_is_never_null(): void
{
    $r = Customer::query()->firstActive();
    if ($r === null) {
        return;
    }

    $_r = $r;
    /** @psalm-check-type-exact $_r = Customer|Builder<Customer> */
}

/** Narrowing to the model recovers the value side for member access. */
function test_value_scope_narrows_to_model(): void
{
    $r = Customer::query()->firstActive();
    if ($r instanceof Customer) {
        $_r = $r;
        /** @psalm-check-type-exact $_r = Customer */
    }
}

/** Non-null scalar value scope: plain int, no Builder union. */
function test_non_null_scalar_value_scope(): void
{
    $_r = Customer::query()->activeCount();
    /** @psalm-check-type-exact $_r = int */
}

/** Static call form (legacy scope via __callStatic) surfaces the value the same way. */
function test_nullable_value_scope_static(): void
{
    $_r = Customer::firstActive();
    /** @psalm-check-type-exact $_r = Customer|Builder<Customer> */
}

/** Regression: a builder-returning scope keeps Builder<Model>. */
function test_builder_returning_scope_unchanged(): void
{
    $_r = Customer::query()->active();
    /** @psalm-check-type-exact $_r = Builder<Customer> */
}

/** Regression: a void scope keeps Builder<Model> (the `?? $this` fallback). */
function test_void_scope_unchanged(): void
{
    $_r = Customer::query()->verified();
    /** @psalm-check-type-exact $_r = Builder<Customer> */
}

/**
 * Value scope declared on an abstract PARENT, queried via a concrete child: the return's `self`
 * pins to the composing parent (AbstractDocument) while the `?? $this` fallback stays the child's
 * builder (Builder<Contract>).
 */
function test_value_scope_self_pins_to_parent(): void
{
    $_r = Contract::query()->firstSigned();
    /** @psalm-check-type-exact $_r = AbstractDocument|Builder<Contract> */
}

/**
 * Value scope returning `?static`: pinned to the queried child as the PLAIN class
 * (Contract|Builder<Contract>), not Contract&static — what `final: true` buys on the return.
 */
function test_value_scope_static_pins_to_child(): void
{
    $_r = Contract::query()->firstSignedStatic();
    /** @psalm-check-type-exact $_r = Contract|Builder<Contract> */
}

/** Value scope on a CUSTOM-builder model: the fallback is the custom builder, not base Builder. */
function test_value_scope_with_custom_builder(): void
{
    $_r = Vehicle::query()->firstElectric();
    /** @psalm-check-type-exact $_r = VehicleBuilder<Vehicle>|Vehicle */
}
?>
--EXPECTF--
TypeDoesNotContainNull on line %d: App\Models\Customer|Illuminate\Database\Eloquent\Builder<App\Models\Customer> does not contain null
