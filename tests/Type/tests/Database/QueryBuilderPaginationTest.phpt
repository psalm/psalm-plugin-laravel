--FILE--
<?php declare(strict_types=1);

use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use App\Models\Customer;

/**
 * Query\Builder pagination rows always hydrate as stdClass, so paginate()/
 * simplePaginate()/cursorPaginate() narrow to the concrete Pagination classes
 * with `<int, \stdClass>` instead of the ungenerified/contract vendor returns.
 */
function query_builder_paginate_is_concrete(): void
{
    $_length = DB::table('users')->paginate();
    /** @psalm-check-type-exact $_length = LengthAwarePaginator<int, \stdClass> */

    $_simple = DB::table('users')->simplePaginate();
    /** @psalm-check-type-exact $_simple = Paginator<int, \stdClass> */

    $_cursor = DB::table('users')->cursorPaginate();
    /** @psalm-check-type-exact $_cursor = CursorPaginator<int, \stdClass> */
}

/** Named-argument calls keep working — param names/order/defaults mirror vendor. */
function query_builder_paginate_named_args(): void
{
    $_length = DB::table('users')->paginate(perPage: 25, pageName: 'p', page: 2);
    /** @psalm-check-type-exact $_length = LengthAwarePaginator<int, \stdClass> */

    $_cursor = DB::table('users')->cursorPaginate(cursorName: 'c');
    /** @psalm-check-type-exact $_cursor = CursorPaginator<int, \stdClass> */
}

function query_builder_paginate_item_type(): void
{
    foreach (DB::table('users')->paginate() as $key => $row) {
        /** @psalm-check-type-exact $key = int */
        /** @psalm-check-type-exact $row = \stdClass */
        echo $key, $row::class;
    }
}

/** Concrete-only methods (absent from the Pagination contracts) resolve without error. */
function query_builder_paginate_concrete_only_methods(): void
{
    $_links = DB::table('users')->paginate()->linkCollection();
    /** @psalm-check-type-exact $_links = \Illuminate\Support\Collection<array-key, mixed> */

    $_simple = DB::table('users')->simplePaginate()->hasMorePagesWhen(true);
    /** @psalm-check-type-exact $_simple = Paginator<int, \stdClass>&static */
}

/** Eloquent\Builder::paginate() is untouched — model rows keep their model template. */
function eloquent_builder_paginate_still_model_typed(): void
{
    $_paginator = Customer::query()->paginate();
    /** @psalm-check-type-exact $_paginator = LengthAwarePaginator<int, Customer> */
}
?>
--EXPECTF--
