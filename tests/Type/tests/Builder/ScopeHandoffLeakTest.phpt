--FILE--
<?php declare(strict_types=1);

use App\Models\CollidingScopeModel;
use App\Models\Customer;

/**
 * Pins the scope-params hand-off cleanup: the producer must NOT write an entry when Psalm
 * storage already declares the method on Eloquent\Builder, because Psalm resolves declared
 * methods without consulting the params provider, leaving the entry unconsumed. A stale entry
 * then shadows a later unrelated call using the same method name, causing false positives.
 *
 * The passthru aggregates (count, sum, exists) are stub-declared on Eloquent\Builder for typing
 * but route through __call at runtime, so scopes CAN shadow them legitimately (the footgun).
 * The producer must avoid the hand-off write for these stub-declared passthru names while
 * still returning the scope's Builder<TModel> return type (ScopeNameCollisionTest depends on that).
 *
 * Before fix: the producer writes $pendingScopeModel['count'] = CollidingScopeModel, but Psalm
 * takes the declared-method path and never calls the params provider, so the entry persists.
 * Customer::query()->count(columns: 'id') then finds the stale entry, returns the scope's empty
 * param list, and Psalm emits InvalidNamedArgument for $columns.
 *
 * After fix: the producer checks getDeclaringMethodId(Builder::count) non-null => skips the write,
 * so Customer::query()->count() is checked against the real Builder::count($columns='*') signature.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1640
 */

/** Trigger the leak: CollidingScopeModel::scopeCount() makes the producer write the handoff. */
function setup_leak(): void
{
    // scopeCount shadows the passthru aggregate at runtime; return type is Builder<CollidingScopeModel>.
    $_result = CollidingScopeModel::query()->count();
    /** @psalm-check-type-exact $_result = Illuminate\Database\Eloquent\Builder<App\Models\CollidingScopeModel> */
}

/**
 * Victim: Customer has no scopeCount, so count() should resolve to the real Builder::count($columns).
 * Before fix: the stale handoff entry makes the params provider return empty params, causing
 * InvalidNamedArgument for $columns. After fix: no stale entry, real signature, no issue.
 */
function test_customer_count_not_shadowed_by_stale_handoff(): void
{
    $_count = Customer::query()->count(columns: 'id');
    /** @psalm-check-type-exact $_count = int<0, max> */
}

/** Reverse call order must also be clean (the handoff should never persist across models). */
function test_reverse_order(): void
{
    $_count = Customer::query()->count(columns: 'email');
    /** @psalm-check-type-exact $_count = int<0, max> */

    $_result = CollidingScopeModel::query()->count();
    /** @psalm-check-type-exact $_result = Illuminate\Database\Eloquent\Builder<App\Models\CollidingScopeModel> */
}
?>
--EXPECTF--
