--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// Skip on Laravel < 12: this test asserts #[Scope]-attributed scope resolution.
// The #[Scope] attribute is Laravel 12+, so on Laravel 11 the plugin correctly does
// not resolve such methods as scopes (see EloquentModelMethods::hasScopeAttribute).
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('12.0.0');
--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\DirectScopeModel;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;

/**
 * Regression test for psalm/psalm-plugin-laravel#1034.
 *
 * A direct call to a #[Scope] method that passes the $query builder explicitly — e.g. one
 * scope calling another, $this->otherScope($query, ...), or any call to an accessible real
 * scope method — invokes the real method and must be type-checked against its full declared
 * signature: params (no left-shift of arguments) AND return type (no fabricated Builder<Model>).
 *
 * The plugin strips the leading $query parameter for the magic-forwarded forms
 * (DirectScopeModel::hasAnyName(...) via __callStatic, $builder->hasAnyName(...)), where
 * Laravel injects it. Applying that stripped signature to a direct call shifted every
 * argument left by one and produced a false-positive InvalidArgument (the explicit Builder
 * checked against the second declared parameter) plus a spurious TooManyArguments.
 *
 * The dispatch-truth classifier ({@see BuilderScopeHandler::isDirectScopeCall}) decides direct
 * vs forwarded from PHP dispatch semantics — the bare name is a real, accessible method — not
 * from the argument shapes. So the calls below classify correctly regardless of whether the
 * first argument is a plain Builder, a Builder subclass, a nullable ?Builder, or a non-variable
 * expression. DirectScopeModel::hasAnyName is public, so an external call from these snippet
 * functions is accessible and resolves as direct, exercising the same path as the issue's
 * in-model sibling form $this->hasAnyName($query, ...).
 */

/**
 * Direct instance call passing the query builder explicitly — no error.
 *
 * @param Builder<DirectScopeModel> $query
 */
function test_direct_scope_call_with_explicit_query(DirectScopeModel $model, Builder $query): void
{
    $model->hasAnyName($query, ['a', 'b']);
}

/**
 * Direct call returns what the method declares (void here), not a fabricated
 * Builder<Model> — the expected AssignmentToVoid proves the real return type applies.
 *
 * @param Builder<DirectScopeModel> $query
 */
function test_direct_scope_call_uses_real_return_type(DirectScopeModel $model, Builder $query): void
{
    $_result = $model->hasAnyName($query, ['a', 'b']);
}

/**
 * Wrong arg AFTER $query on a direct call: reported as argument 2 (real signature).
 *
 * @param Builder<DirectScopeModel> $query
 */
function test_direct_scope_call_wrong_second_arg(DirectScopeModel $model, Builder $query): void
{
    $model->hasAnyName($query, 'not-a-list');
}

/**
 * A NULLABLE Builder first argument (two atomics) no longer flips the classification to
 * forwarded — dispatch truth keeps it direct, so the real signature applies and the null is a
 * PossiblyNullArgument against the real $query param, not a false InvalidArgument from the
 * stripped signature. Regression for the fa3 shape.
 *
 * @param Builder<DirectScopeModel>|null $query
 */
function test_direct_scope_call_nullable_query_arg(DirectScopeModel $model, ?Builder $query): void
{
    $model->hasAnyName($query, ['a', 'b']);
}

/** Forwarded builder-instance call still uses the stripped (query-less) signature. */
function test_forwarded_scope_call_still_strips_query(): void
{
    $_result = DirectScopeModel::query()->hasAnyName(['a', 'b']);
    /** @psalm-check-type-exact $_result = Builder<DirectScopeModel> */
}

/** Forwarded call with a wrong arg keeps stripped positions: reported as argument 1. */
function test_forwarded_scope_call_wrong_arg(): void
{
    $_result = DirectScopeModel::query()->hasAnyName('not-a-list');
    /** @psalm-check-type-exact $_result = Builder<DirectScopeModel> */
}

/**
 * Custom-builder model: the explicitly-passed builder is a Builder subclass (VehicleBuilder),
 * and electric() is a public #[Scope], so the call is accessible and classifies as direct —
 * the real void return drives AssignmentToVoid.
 */
function test_direct_scope_call_with_custom_builder(Vehicle $vehicle): void
{
    $query = Vehicle::query();
    $_result = $vehicle->electric($query);
}

/**
 * Legacy scopeXxx() direct calls are immune to scope-param stripping: the
 * `scope`-prefixed name never matches the strip logic (keyed on the bare scope
 * name), so the real signature (leading $query, Builder return) applies.
 */
function test_direct_legacy_scope_call(Customer $customer): void
{
    $query = Customer::query();
    $_result = $customer->scopeOfName($query, 'Ada');
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}
?>
--EXPECTF--
AssignmentToVoid on line %d: Cannot assign $_result to type void
InvalidArgument on line %d: Argument 2 of App\Models\DirectScopeModel::hasAnyName expects list<string>, but 'not-a-list' provided
PossiblyNullArgument on line %d: Argument 1 of App\Models\DirectScopeModel::hasAnyName cannot be null, possibly null value provided
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::hasanyname expects list<string>, but 'not-a-list' provided
AssignmentToVoid on line %d: Cannot assign $_result to type void
