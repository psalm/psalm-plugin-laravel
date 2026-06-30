--FILE--
<?php declare(strict_types=1);

use App\Models\Admin;
use App\Models\WorkOrder;

/**
 * Dynamic where{Column} method arguments must be type-checked against the
 * model's declared @property type. Before #928 the handler returned a variadic
 * mixed signature so any value (e.g. int passed to a string column) was
 * silently accepted.
 *
 * Scope: Relation chains only (where MethodForwardingHandler fires) and only
 * the single-argument value form. Multi-argument forms (Laravel's runtime
 * `dynamicWhere` silently drops everything past the first parameter anyway)
 * fall through to the permissive variadic-mixed signature so users coming
 * from Larastan or relying on the operator-form pattern aren't broken.
 *
 * Object/array/iterable column types (Carbon, BackedEnum, json casts) also
 * fall through — Laravel accepts coerced strings/ints at the query layer and
 * narrowing to the property type would mass-regress real codebases.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/928
 */

function test_dynamic_where_value_form_matches_column_type(): void {
    $r = (new WorkOrder())->invoice();
    // OK — invoice_number is @property string $invoice_number
    $r->whereInvoiceNumber('INV-2024-001');
    // OK — status is @property string $status
    $r->whereStatus('paid');
}

function test_dynamic_where_value_form_rejects_wrong_string_type(): void {
    $r = (new WorkOrder())->invoice();
    // Error — invoice_number is string, int provided
    $r->whereInvoiceNumber(123);
    // Error — status is string, int provided
    $r->whereStatus(123);
}

function test_dynamic_where_propagates_float_column_type(): void {
    // Part::$unit_price is @property float — typed param must come from the resolved
    // column type, not a hardcoded scalar. A regression that returned `string` for
    // every column would not catch the int- or string-against-float mismatches below.
    $r = (new WorkOrder())->parts();
    // OK — passes a float literal to a float column
    $r->whereUnitPrice(9.99);
    // OK — Psalm's scalar coercion accepts int for a float param
    $r->whereUnitPrice(10);
    // Error — string is not a float
    $r->whereUnitPrice('not-a-float');
}

function test_dynamic_where_skips_non_scalar_columns(): void {
    // Customer::$email_verified_at is @property CarbonInterface|null. Laravel accepts a
    // string at the query layer and coerces it; emitting InvalidArgument here would
    // mass-regress real Laravel codebases that pass ISO date strings to whereXxx().
    // The handler must fall through to the variadic-mixed fallback for non-scalar
    // column types (objects, arrays, iterables).
    $r = (new Admin())->customers();
    $r->whereEmailVerifiedAt('2024-01-01');
    $r->whereEmailVerifiedAt(null);
}

function test_dynamic_where_unknown_column_skips_validation(): void {
    $r = (new WorkOrder())->invoice();
    // No @property nonExistentColumn — handler returns null, Psalm falls back
    // to the variadic-mixed signature; no validation performed.
    $r->whereNonExistentColumn(123);
}

function test_dynamic_where_multi_segment_falls_back_to_variadic(): void {
    // Multi-segment forms (issue #927) take one argument per segment, each with
    // potentially different column types. The validator caches multi-segment
    // success as `null` so getMethodParams() does NOT consume a Union and apply
    // single-typed-param checking — that would otherwise narrow segment 1's
    // arg to invoice_number's type and reject correct values in segment 2.
    // Passing the wrong type in either position is therefore silently accepted
    // (the variadic-mixed fallback governs argument arity only).
    $r = (new WorkOrder())->invoice();
    // invoice_number AND status are both strings, but the multi-segment path
    // intentionally skips the typed-param hand-off; int args don't error.
    $r->whereInvoiceNumberAndStatus(123, 456);
    $r->whereInvoiceNumberOrStatus(123, 456);
}

function test_dynamic_where_multi_arg_form_falls_back_to_variadic(): void {
    // Laravel's Builder::dynamicWhere silently drops the second+ arguments and
    // always uses '=' as the operator (see Query\Builder::addDynamic). The 2-arg
    // operator form is a runtime bug, but emitting TooManyArguments here would
    // break the issue caveat that asks for operator-form tolerance. Both args
    // pass through the variadic-mixed fallback without type validation.
    $r = (new WorkOrder())->invoice();
    $r->whereStatus('=', 'paid');
    $r->whereStatus('like', '%paid%');
    // Even with a value-position type mismatch the variadic-mixed fallback
    // accepts the call; type-checking 2-arg forms is out of scope.
    $r->whereStatus('=', 123);
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of %s::whereinvoicenumber expects %s, but %s provided
InvalidArgument on line %d: Argument 1 of %s::wherestatus expects %s, but %s provided
InvalidArgument on line %d: Argument 1 of %s::whereunitprice expects %s, but %s provided
