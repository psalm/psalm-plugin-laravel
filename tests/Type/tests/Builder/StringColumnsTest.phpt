--FILE--
<?php declare(strict_types=1);

// Laravel accepts a single column name as a string (not only an array) on the paginators
// and on Query\Builder::find across all supported versions: get()/first() run
// Arr::wrap($columns), which wraps a scalar into a one-element list. The default ['*'] is
// an array; the string form is any single column name. The stubs widen $columns to
// non-empty-string|<array form> so `->paginate(15, 'name')` is no longer a false-positive
// ArgumentTypeCoercion.

use App\Models\Customer;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

function test_paginator_string_columns(): void {
    $_len = Customer::query()->paginate(15, 'name');
    /** @psalm-check-type-exact $_len = LengthAwarePaginator<int, Customer> */

    $_simple = Customer::query()->simplePaginate(15, 'name');
    /** @psalm-check-type-exact $_simple = Paginator<int, Customer> */

    $_cursor = Customer::query()->cursorPaginate(15, 'name');
    /** @psalm-check-type-exact $_cursor = CursorPaginator<int, Customer> */
}

function test_query_builder_find_string_column(): void {
    // a single string column is accepted (not only an array)
    $_row = DB::table('customers')->find('1', 'name');
    /** @psalm-check-type-exact $_row = stdClass|null */
}
?>
--EXPECTF--
