--FILE--
<?php declare(strict_types=1);

use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Illuminate\Contracts\Pagination\Paginator as PaginatorContract;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/** @param LengthAwarePaginator<string, int> $paginator */
function length_aware_items(LengthAwarePaginator $paginator): void
{
    $_items = $paginator->items();
    /** @psalm-check-type-exact $_items = array<string, int> */
}

/** @param Paginator<string, int> $paginator */
function simple_items(Paginator $paginator): void
{
    $_items = $paginator->items();
    /** @psalm-check-type-exact $_items = array<string, int> */
}

/** @param CursorPaginator<string, int> $paginator */
function cursor_items(CursorPaginator $paginator): void
{
    $_items = $paginator->items();
    /** @psalm-check-type-exact $_items = array<int|string, int> */
}

/** @param PaginatorContract<string, int> $paginator */
function contract_items(PaginatorContract $paginator): void
{
    $_items = $paginator->items();
    /** @psalm-check-type-exact $_items = array<string, int> */
}

/** @param CursorPaginatorContract<string, int> $paginator */
function cursor_contract_items(CursorPaginatorContract $paginator): void
{
    $_items = $paginator->items();
    /** @psalm-check-type-exact $_items = array<int|string, int> */
}
function cursor_items_constructed_from_named_keys(): void
{
    // setItems() ends with values(), so the runtime list is reindexed whatever the input keys were.
    $paginator = new CursorPaginator(['named' => 42], 10);
    $_items = $paginator->items();
    /** @psalm-check-type-exact $_items = array<'named'|int, 42> */

    $_first = $_items[0];
    /** @psalm-check-type-exact $_first = 42 */
}

--EXPECTF--
