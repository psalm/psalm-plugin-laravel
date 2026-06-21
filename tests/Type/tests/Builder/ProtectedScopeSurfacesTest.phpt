--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// Skip on Laravel < 12: this test asserts #[Scope]-attributed scope resolution.
// The #[Scope] attribute is Laravel 12+, so on Laravel 11 the plugin correctly does
// not resolve such methods as scopes (see EloquentModelMethods::hasScopeAttribute).
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('12.0.0');
--FILE--
<?php declare(strict_types=1);

use App\Models\DirectScopeModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pins a PROTECTED #[Scope] (Laravel's documented convention) on the two surfaces that work
 * today: a builder instance (M::query()->scope()) and a relation chain
 * ($owner->relation()->scope()). Runtime: Builder::__call / Relation::__call both consult
 * hasNamedScope() and route to callNamedScope(); the relation decorates the forwarded call and
 * returns itself, so the relation type is preserved for further chaining.
 *
 * Reuses DirectScopeModel::active (a protected #[Scope]). A protected method is inaccessible from
 * outside the class, so PHP routes BOTH a static and a direct-instance call to __callStatic/__call
 * and Laravel forwards to the scope (runtime-valid) — but Psalm checks the real protected signature
 * and false-positives. Those two forms bound the supported surfaces: the static call
 * (DirectScopeModel::active()) draws InvalidStaticInvocation in StaticBuilderMethodsTest, and the
 * direct-instance call is pinned below as a documented false-positive TooFewArguments.
 */

/** Protected scope on a builder instance resolves to Builder<Model>. */
function test_protected_scope_on_builder_instance(): void
{
    $_result = DirectScopeModel::query()->active();
    /** @psalm-check-type-exact $_result = Builder<DirectScopeModel> */
}

/** Negative: the scope takes no args after $query, so an extra argument is rejected. */
function test_protected_scope_rejects_extra_arg_on_builder(): void
{
    DirectScopeModel::query()->active('extra');
}

/**
 * Protected scope on a relation chain keeps the relation type (HasMany), because Laravel's
 * Relation::__call decorates the forwarded scope call and returns the relation for chaining.
 */
function test_protected_scope_on_relation_chain(DirectScopeModel $model): void
{
    $_result = $model->children()->active();
    /** @psalm-check-type-exact $_result = HasMany<DirectScopeModel, DirectScopeModel> */
}

/** Negative: the same no-arg scope signature applies on the relation chain. */
function test_protected_scope_rejects_extra_arg_on_relation(DirectScopeModel $model): void
{
    $model->children()->active('extra');
}

/**
 * Documented FALSE positive (boundary): a direct protected-instance call is inaccessible from
 * outside, so at runtime PHP routes it to Model::__call -> newQuery()->active() and the scope runs
 * with an injected $query (valid). But Psalm sees the real active(Builder $query) signature and
 * reports the missing $query as TooFewArguments. Mirrors the protected-static false positive in
 * StaticBuilderMethodsTest.
 */
function test_protected_scope_direct_instance_call_false_positive(DirectScopeModel $model): void
{
    $model->active();
}
?>
--EXPECTF--
TooManyArguments on line %d: Too many arguments for Illuminate\Database\Eloquent\Builder::active - expecting 0 but saw 1
TooManyArguments on line %d: Too many arguments for Illuminate\Database\Eloquent\Relations\HasMany::active - expecting 0 but saw 1
TooFewArguments on line %d: Too few arguments for method App\Models\DirectScopeModel::active saw 0
