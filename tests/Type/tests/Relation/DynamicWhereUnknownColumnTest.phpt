--FILE--
<?php declare(strict_types=1);

use App\Models\Invoice;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * When resolveDynamicWhereClauses is enabled (default), a where{Column} call
 * for a column NOT declared as @property on the model must fall through to mixed
 * without emitting UndefinedMagicMethod. This is the negative case for the
 * dynamic where resolution: unrecognised columns are safe no-ops.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/647
 */

function test_dynamic_where_unknown_column_falls_through_to_mixed(): void {
    /** @var HasOne<Invoice, WorkOrder> $r */
    $r = (new WorkOrder())->invoice();
    // "nonexistent_column" is not a @property on Invoice — handler returns null,
    // Psalm falls to __call on the Relation, which returns mixed.
    $_ = $r->whereNonExistentColumn('x')->first();
    /** @psalm-check-type-exact $_ = mixed */
}
?>
--EXPECTF--
