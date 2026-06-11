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
 *      scope is unreachable dead code. The runtime value is whatever the real method returns.
 *        - find() returns Model|null  -> the plugin's Builder<M> here is WRONG (see the BUG note).
 *        - latest() returns the builder -> the plugin's Builder<M> coincidentally matches.
 *
 *   B. Names a scope may legitimately shadow via __call (count is passthru-only on
 *      Eloquent\Builder; whereActive is a dynamic where): __call DOES fire and hasNamedScope
 *      wins, so the scope runs and returns the builder at runtime. The plugin's Builder<M> is
 *      CORRECT — this is the classic count()/sum()/exists() scope-shadowing footgun.
 *
 * CollidingScopeModel is a dedicated archetype (never a shared model) so these pathological
 * scope names cannot perturb the many suites that import Customer/Vehicle/etc.
 *
 * KNOWN BUG (find return type, pinned-as-limitation below, tracked in #1039):
 * BuilderScopeHandler::getMethodReturnType lacks the isRealBuilderMethod() guard that
 * getMethodParams() carries, so for a scope name that collides with a REAL Eloquent\Builder
 * method it overrides the real return type with Builder<M>.
 * Argument checking is unaffected (the params path keeps the guard and falls back to the real
 * method's signature), which test_find_argument_checking_uses_real_builder_signature pins. The
 * assertion is deliberately pinned to today's wrong output so a future refactor that extends the
 * guard to the return path flips this test to the correct Model|null. Note this is find-SPECIFIC:
 * count is passthru-only (bucket B), so its Builder<M> is correct, not a bug.
 */

/**
 * BUG (pinned-as-limitation): find() is a real Eloquent\Builder method, so at runtime the scope
 * is dead code and CollidingScopeModel::query()->find(1) returns CollidingScopeModel|null. The
 * plugin currently infers Builder<CollidingScopeModel> because the return-type path lacks the
 * isRealBuilderMethod() guard. Pinned to the wrong output as a tripwire — see the file docblock.
 */
function test_find_real_eloquent_method_return_type_is_shadowed(): void
{
    $_result = CollidingScopeModel::query()->find(1);
    /** @psalm-check-type-exact $_result = Builder<CollidingScopeModel> */
}

/**
 * Argument checking for find() uses the REAL Builder::find signature ($id, $columns), not the
 * colliding scope's params — proof the params path keeps the isRealBuilderMethod guard even
 * though the return path does not. Both the surplus third argument and the wrong column type
 * are reported against the real method.
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
 * latest() is a real Eloquent\Builder method whose own return value IS the builder, so even
 * though the like-named scope is dead code the inferred Builder<M> matches runtime.
 */
function test_latest_real_eloquent_method_returns_builder(): void
{
    $_result = CollidingScopeModel::query()->latest('created_at');
    /** @psalm-check-type-exact $_result = Builder<CollidingScopeModel> */
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
