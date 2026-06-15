--FILE--
<?php declare(strict_types=1);

use App\Models\CollidingScopeModel;

/**
 * A scope whose name matches a Query\Builder-only method must win in the params provider,
 * mirroring Laravel's runtime dispatch: Builder::__call checks hasNamedScope() BEFORE
 * forwardCallTo($this->query, ...).
 *
 * Before the fix, ModelMethodHandler::getMethodParams checked the Query\Builder branch BEFORE
 * the scope branch — the inverse of the return-type provider's order and of Laravel's actual
 * dispatch. A model scope named `orderBy(Builder $q, int $priority)` was checked against
 * Query\Builder::orderBy(string $column, ...) instead of its own signature, so:
 *   - Model::orderBy(5)        → false InvalidArgument (int vs string column)
 *   - Model::orderBy('string') → silently passed (string matched Query\Builder)
 *
 * After the fix the scope params are checked first:
 *   - Model::orderBy(5)        → passes (scope accepts int)
 *   - Model::orderBy('string') → InvalidArgument (scope expects int)
 *
 * CollidingScopeModel::scopeOrderBy uses int $priority — a type incompatible with
 * Query\Builder::orderBy's string $column — to make the precedence directly observable.
 *
 * Control: CollidingScopeModel::count() is a passthru-aggregate scope (also a collision with
 * a Query\Builder method); it has no non-query params so both orderings produce the same
 * result — it is included as a regression guard that the fix doesn't break this case.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1046
 */

/** Positive: scope int param accepts an int — the scope wins over Query\Builder::orderBy. */
function test_scope_int_param_accepted(): void
{
    $_result = CollidingScopeModel::orderBy(5);
    /** @psalm-check-type-exact $_result = Illuminate\Database\Eloquent\Builder<App\Models\CollidingScopeModel> */
}

/** Negative: scope int param rejects a string — verified against the SCOPE signature. */
function test_scope_int_param_rejects_string(): void
{
    CollidingScopeModel::orderBy('column');
}

/** Control: passthru-aggregate scope (count) still resolves without regression. */
function test_aggregate_scope_unaffected(): void
{
    $_result = CollidingScopeModel::count();
    /** @psalm-check-type-exact $_result = Illuminate\Database\Eloquent\Builder<App\Models\CollidingScopeModel> */
}

/**
 * Upstream-blocked limitation (not a pending plugin fix): the scope-first precedence applies only to
 * static model calls routed through ModelMethodHandler. Builder-instance calls (query()->orderBy())
 * resolve orderBy through Eloquent\Builder's @mixin Query\Builder, which rewrites the call to
 * Query\Builder::orderBy BEFORE argument checking. Psalm keys its params/return-type provider lookups
 * on that resolved Query\Builder class, never on the Builder host where BuilderScopeHandler is
 * registered, so the scope params are never consulted and an int arg still raises InvalidArgument
 * against the Query\Builder string-column signature.
 *
 * Not fixable at the plugin level — it is an upstream Psalm limitation: @mixin-forwarded methods are
 * not offered to providers registered on the mixin host, and the params/existence provider events
 * expose no receiver to distinguish a colliding-scope model from a plain Builder. (Forcing
 * interception via an existence provider only reverses the return-type/params hook order and makes
 * Psalm throw on the absent Builder::orderBy storage.) Keep this test until upstream changes.
 *
 * Tracked upstream: https://github.com/vimeo/psalm/issues/11880 (@mixin-host method providers not
 * consulted for mixin-forwarded methods, a beta19 regression). When it is fixed this test starts
 * failing and must be converted to assert the correct output.
 */
function test_instance_call_limitation(): void
{
    CollidingScopeModel::query()->orderBy(5);
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of App\Models\CollidingScopeModel::orderby expects int, but 'column' provided
InvalidArgument on line %d: %s
