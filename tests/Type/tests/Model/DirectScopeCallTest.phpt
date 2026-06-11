--FILE--
<?php declare(strict_types=1);

use App\Models\DirectScopeModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Regression test for psalm/psalm-plugin-laravel#1034.
 *
 * A direct instance call to a #[Scope] (or legacy scopeXxx()) method that passes the
 * $query builder explicitly — e.g. one scope calling another, $this->otherScope($query, ...) —
 * invokes the real method and must be type-checked against its full declared signature.
 *
 * The plugin strips the leading $query parameter for the magic-forwarded forms
 * (DirectScopeModel::hasAnyName(...) via __callStatic, $builder->hasAnyName(...)), where
 * Laravel injects it. Applying that stripped signature to a direct call shifted every
 * argument left by one and produced a false-positive InvalidArgument (the explicit Builder
 * checked against the second declared parameter) plus a spurious TooManyArguments.
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

/** Forwarded builder-instance call still uses the stripped (query-less) signature. */
function test_forwarded_scope_call_still_strips_query(): void
{
    $_result = DirectScopeModel::query()->hasAnyName(['a', 'b']);
    /** @psalm-check-type-exact $_result = Builder<DirectScopeModel> */
}
?>
--EXPECT--
