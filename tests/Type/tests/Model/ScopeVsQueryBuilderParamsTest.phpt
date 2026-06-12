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
 * Known limitation: the scope-first fix applies only to static model calls routed through
 * ModelMethodHandler. Builder-instance calls (query()->orderBy()) reach Query\Builder::orderBy
 * via Eloquent\Builder's @mixin QueryBuilder BEFORE BuilderScopeHandler fires, so the scope
 * params are never consulted and an int arg still raises InvalidArgument against the
 * Query\Builder string-column signature. Tracked as a dedicated follow-up fix.
 */
function test_instance_call_limitation(): void
{
    CollidingScopeModel::query()->orderBy(5);
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of App\Models\CollidingScopeModel::orderby expects int, but 'column' provided
InvalidArgument on line %d: %s
