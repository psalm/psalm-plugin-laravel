--FILE--
<?php declare(strict_types=1);

use App\Models\Invoice;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Tests for dynamic where{Column} method resolution on Eloquent Relation chains.
 *
 * Laravel's Builder::__call converts whereTitle('foo') to where('title', '=', 'foo').
 * With resolveDynamicWhereClauses enabled (default), these are resolved on relation chains when the
 * column exists in the model's @property annotations.
 *
 * Uses HasOne<Invoice, WorkOrder> with an explicit @var annotation to get a stable
 * relation type regardless of batch analysis context.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/647
 */

// Multi-word column: whereInvoiceNumber matches @property string $invoice_number.
// Psalm lowercases method names, so "whereInvoiceNumber" → "whereinvoicenumber",
// and "invoice_number" normalises to "invoicenumber" — they match.
function test_dynamic_where_multi_word_column(): void {
    /** @var HasOne<Invoice, WorkOrder> $r */
    $r = (new WorkOrder())->invoice();
    $_ = $r->whereInvoiceNumber('INV-2024-001')->first();
    /** @psalm-check-type-exact $_ = App\Models\Invoice|null */
}

// Single-word column: whereStatus matches @property string $status.
function test_dynamic_where_single_word_column(): void {
    /** @var HasOne<Invoice, WorkOrder> $r */
    $r = (new WorkOrder())->invoice();
    $_ = $r->whereStatus('paid')->first();
    /** @psalm-check-type-exact $_ = App\Models\Invoice|null */
}
?>
--EXPECTF--
