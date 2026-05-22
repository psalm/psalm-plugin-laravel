--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-no-dynamic-where.xml
--FILE--
<?php declare(strict_types=1);

use App\Models\Invoice;
use App\Models\WorkOrder;

/**
 * When resolveDynamicWhereClauses is disabled (<resolveDynamicWhereClauses value="false" />),
 * dynamic where{Column} methods must NOT be resolved even for valid @property columns.
 * The call falls through to __call on the Relation and returns mixed.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/647
 */

function test_dynamic_where_not_resolved_when_disabled(): void {
    $r = (new WorkOrder())->invoice();
    // whereInvoiceNumber IS a valid @property on Invoice, but the feature is off —
    // must fall through to mixed instead of preserving the relation generic type.
    $_ = $r->whereInvoiceNumber('INV-2024-001');
    /** @psalm-check-type-exact $_ = mixed */
}

// Multi-segment dynamic where (issue #927) must respect the disabled flag too.
// A regression that wired the segment-split path outside the $enableDynamicWhere
// gate would still resolve here; verify the call collapses to mixed.
function test_dynamic_where_multi_segment_not_resolved_when_disabled(): void {
    $r = (new WorkOrder())->invoice();
    $_ = $r->whereInvoiceNumberAndStatus('INV-2024-001', 'paid');
    /** @psalm-check-type-exact $_ = mixed */
}
?>
--EXPECTF--
