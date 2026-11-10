--FILE--
<?php declare(strict_types=1);

use App\Models\DirectScopeModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pins a PROTECTED #[Scope] (Laravel's documented convention) on every surface: a builder
 * instance (M::query()->scope()), a relation chain ($owner->relation()->scope()) and a call on
 * the model itself. Runtime: Builder::__call / Relation::__call both consult hasNamedScope()
 * and route to callNamedScope(); the relation decorates the forwarded call and returns itself,
 * so the relation type is preserved for further chaining.
 *
 * Reuses DirectScopeModel::active (a protected #[Scope]). A protected method is inaccessible from
 * outside the class, so PHP routes BOTH a static and a direct-instance call to __callStatic/__call
 * and Laravel forwards to the scope (runtime-valid); the static form is pinned in
 * StaticBuilderMethodsTest.
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
 * A direct protected-instance call is inaccessible from outside, so at runtime PHP routes it to
 * Model::__call -> newQuery()->active() and the scope runs with an injected $query. Psalm 7.2
 * models that forward once the visibility provider leaves a method declared protected
 * to Psalm, so the real active(Builder $query) signature is no longer checked.
 */
function test_protected_scope_direct_instance_call_is_forwarded(DirectScopeModel $model): void
{
    $_result = $model->active();
    /** @psalm-check-type-exact $_result = Builder<DirectScopeModel> */
}
?>
--EXPECTF--
TooManyArguments on line %d: Too many arguments for Illuminate\Database\Eloquent\Builder::active - expecting 0 but saw 1
TooManyArguments on line %d: Too many arguments for Illuminate\Database\Eloquent\Relations\HasMany::active - expecting 0 but saw 1
