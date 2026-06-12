--FILE--
<?php declare(strict_types=1);

use App\Models\CollidingScopeModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pins how scope names that COLLIDE with built-in query methods resolve, anchored to
 * Eloquent\Builder::__call (vendor/laravel/framework/.../Database/Eloquent/Builder.php,
 * ~line 2266): __call fires only for names that are NOT real methods on the builder, and
 * inside __call hasNamedScope() is consulted BEFORE the $passthru aggregate forward and before
 * forwardCallTo()/dynamicWhere(). The collision outcome therefore depends on WHICH bucket the
 * colliding name falls into:
 *
 *   A. Real Eloquent\Builder methods (find, latest): __call never fires, so the like-named
 *      scope is unreachable dead code. The producer skips these names (it tests PHP's runtime
 *      method_exists on the real class), so Psalm uses the real method's return type:
 *        - find() returns TModel|null  -> CollidingScopeModel|null (the real signature; #1039).
 *        - latest() returns $this      -> Builder<M>&static.
 *
 *   B. Names a scope may legitimately shadow via __call (count is passthru-only on
 *      Eloquent\Builder; whereActive is a dynamic where): __call DOES fire and hasNamedScope
 *      wins, so the scope runs and returns the builder at runtime. The plugin's Builder<M> is
 *      CORRECT — this is the classic count()/sum()/exists() scope-shadowing footgun. The producer
 *      keeps these scope-eligible (method_exists is false for them on the real Eloquent\Builder).
 *
 * CollidingScopeModel is a dedicated archetype (never a shared model) so these pathological
 * scope names cannot perturb the many suites that import Customer/Vehicle/etc.
 *
 * Resolves #1039: the producer now skips scope handling for names that are real Eloquent\Builder
 * methods, so a scope can no longer mask find()'s real TModel|null return. The distinction is
 * made with PHP's runtime method_exists (not Psalm's), because the stub declares the $passthru
 * aggregates (count, sum) on Eloquent\Builder for typing while Laravel routes them through __call.
 */

/**
 * find() is a real Eloquent\Builder method, so at runtime the like-named scope is dead code and
 * CollidingScopeModel::query()->find(1) returns CollidingScopeModel|null. The producer skips
 * scope handling for real Eloquent\Builder methods, so Psalm uses the real find() signature
 * instead of masking it with Builder<M> (resolves #1039).
 */
function test_find_real_eloquent_method_return_type_is_not_shadowed(): void
{
    $_result = CollidingScopeModel::query()->find(1);
    /** @psalm-check-type-exact $_result = CollidingScopeModel|null */
}

/**
 * Argument checking for find() uses the REAL Builder::find signature ($id, $columns), not the
 * colliding scope's params. The producer skips find (a real public Eloquent\Builder method), so
 * no scope params are handed off and Psalm checks arguments against the real method. Both the
 * surplus third argument and the wrong column type are reported against it.
 */
function test_find_argument_checking_uses_real_builder_signature(): void
{
    CollidingScopeModel::query()->find(1, 2, 3);
}

/**
 * count() is passthru-only on Eloquent\Builder, so __call fires and hasNamedScope wins over the
 * aggregate forward: the scope runs and returns the builder at runtime. The inferred Builder<M>
 * is CORRECT — the well-known footgun where a scopeCount()/scopeSum() shadows the aggregate.
 */
function test_count_passthru_aggregate_is_shadowed_by_scope(): void
{
    $_result = CollidingScopeModel::query()->count();
    /** @psalm-check-type-exact $_result = Builder<CollidingScopeModel> */
}

/**
 * latest() is a real Eloquent\Builder method (@return $this), so the producer skips the like-named
 * scope and Psalm uses the real signature: Builder<M>&static. The dead scope never participates.
 */
function test_latest_real_eloquent_method_returns_builder(): void
{
    $_result = CollidingScopeModel::query()->latest('created_at');
    /** @psalm-check-type-exact $_result = Builder<CollidingScopeModel>&static */
}

/**
 * whereActive() parses as a dynamic where (where('active', ...)) but is declared as a scope.
 * On a builder instance hasNamedScope wins over dynamicWhere, so it resolves to the scope
 * returning Builder<M>.
 */
function test_where_named_scope_wins_over_dynamic_where_on_builder(): void
{
    $_result = CollidingScopeModel::query()->whereActive();
    /** @psalm-check-type-exact $_result = Builder<CollidingScopeModel> */
}

/** Same precedence when forwarded statically through Model::__callStatic. */
function test_where_named_scope_wins_over_dynamic_where_when_static(): void
{
    $_result = CollidingScopeModel::whereActive();
    /** @psalm-check-type-exact $_result = Builder<CollidingScopeModel> */
}

/**
 * Negative: the scope takes no args after $query, so a value argument is rejected. A dynamic
 * where (where('active', $value)) WOULD accept it — this proves the scope, not dynamicWhere,
 * supplied the parameter list.
 */
function test_where_named_scope_rejects_value_arg_on_builder(): void
{
    CollidingScopeModel::query()->whereActive('extra');
}

/** Same negative on the static forwarding path. */
function test_where_named_scope_rejects_value_arg_when_static(): void
{
    CollidingScopeModel::whereActive('extra');
}
?>
--EXPECTF--
TooManyArguments on line %d: Too many arguments for method Illuminate\Database\Eloquent\Builder::find - saw 3
InvalidArgument on line %d: Argument 2 of Illuminate\Database\Eloquent\Builder::find expects list<non-empty-string>, but 2 provided
TooManyArguments on line %d: Too many arguments for Illuminate\Database\Eloquent\Builder::whereactive - expecting 0 but saw 1
TooManyArguments on line %d: Too many arguments for App\Models\CollidingScopeModel::whereactive - expecting 0 but saw 1
