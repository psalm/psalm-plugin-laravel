--FILE--
<?php declare(strict_types=1);

use App\Models\Invoice;
use App\Models\WorkOrder;

/**
 * Tests for multi-segment dynamic where{Column} methods on relation chains.
 *
 * Laravel's `Builder::dynamicWhere` splits the suffix after "where" on
 * `(?:And|Or)(?=[A-Z])` and treats each segment as a separate column condition,
 * so `whereInvoiceNumberAndStatus('INV-X', 'paid')` is equivalent to
 * `where('invoice_number', 'INV-X')->where('status', 'paid')`. The handler must
 * recognise these calls as fluent so chaining preserves the Relation generic
 * type, and must reject calls where any segment doesn't correspond to a
 * declared @property (falling through to mixed, consistent with single-segment
 * unknown-column behaviour in DynamicWhereUnknownColumnTest).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/927
 */

// Valid AND form: both invoice_number and status are @property on Invoice.
// The two-segment call returns Invoice|null after ->first(), matching the
// single-segment behaviour from DynamicWhereMethodTest.
function test_dynamic_where_and_multi_segment_valid(): void {
    $r = (new WorkOrder())->invoice();
    $_ = $r->whereInvoiceNumberAndStatus('INV-2024-001', 'paid')->first();
    /** @psalm-check-type-exact $_ = App\Models\Invoice|null */
}

// Valid Or form: both invoice_number and status are @property on Invoice.
function test_dynamic_where_or_multi_segment_valid(): void {
    $r = (new WorkOrder())->invoice();
    $_ = $r->whereInvoiceNumberOrStatus('INV-2024-001')->first();
    /** @psalm-check-type-exact $_ = App\Models\Invoice|null */
}

// Mixed three-segment And/Or: every segment must validate.
function test_dynamic_where_mixed_multi_segment_valid(): void {
    $r = (new WorkOrder())->invoice();
    $_ = $r->whereInvoiceNumberAndStatusOrInvoiceNumber('INV-2024-001', 'paid', 'INV-2024-002')->first();
    /** @psalm-check-type-exact $_ = App\Models\Invoice|null */
}

// One bad segment → fall through to mixed (consistent with single-segment unknown
// column behaviour; the plugin doesn't emit a warning here).
function test_dynamic_where_multi_segment_with_unknown_column_falls_through(): void {
    $r = (new WorkOrder())->invoice();
    // 'nope' is not a @property on Invoice; the whole call must not be recognised
    // as fluent and the return type collapses to mixed via __call.
    $_ = $r->whereInvoiceNumberAndNope('INV-2024-001', 'x');
    /** @psalm-check-type-exact $_ = mixed */
}

// All segments unknown → also falls through.
function test_dynamic_where_multi_segment_all_unknown_falls_through(): void {
    $r = (new WorkOrder())->invoice();
    $_ = $r->whereFooAndBar('a', 'b');
    /** @psalm-check-type-exact $_ = mixed */
}

// Cache key includes the ORIGINAL-case method name from the AST, so the same
// lowercase shape spelled two ways produces two different cache outcomes:
//   - whereInvoiceNumberAndStatus → splits on `(?:And|Or)(?=[A-Z])` → valid
//   - whereinvoicenumberandstatus → no capital after `and` → single segment
//     `invoicenumberandstatus`, no such column → falls through to mixed.
// PHP method dispatch is case-insensitive, so both reach the same Relation
// method, but php-parser preserves source casing and our cache key relies on
// it. A regression that lowercased the cache key would conflate these.
function test_dynamic_where_cache_key_preserves_original_case(): void {
    $r = (new WorkOrder())->invoice();
    // Splittable form: valid two-segment call, returns Relation type.
    $_a = $r->whereInvoiceNumberAndStatus('INV-2024-001', 'paid')->first();
    /** @psalm-check-type-exact $_a = App\Models\Invoice|null */

    // Non-splittable form: single segment that isn't a column → mixed.
    $_b = $r->whereinvoicenumberandstatus('INV-2024-001', 'paid');
    /** @psalm-check-type-exact $_b = mixed */
}

// Dynamic-name method call (`$r->{$name}('x')`). Psalm's MethodCallAnalyzer
// short-circuits dynamic-name dispatch before invoking return-type providers,
// so the plugin never sees this call regardless of its handler logic. The
// assertion locks in the Psalm-level behaviour: if a future Psalm version
// resolves literal-string dynamic names and starts firing providers, this
// test will flip and force us to revisit the Expr fallback in
// resolveDynamicWhereOnRelation.
function test_dynamic_where_skipped_for_dynamic_method_name(): void {
    $r = (new WorkOrder())->invoice();
    $name = 'whereInvoiceNumber';
    $_ = $r->{$name}('INV-2024-001');
    /** @psalm-check-type-exact $_ = mixed */
}
?>
--EXPECTF--
