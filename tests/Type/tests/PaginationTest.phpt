--FILE--
<?php declare(strict_types=1);

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;

/**
 * @param Collection<int, string> $items
 */
function length_aware_paginator_accepts_collection(Collection $items): LengthAwarePaginator
{
    return new LengthAwarePaginator($items, 100, 15);
}

/**
 * @param Collection<int, string> $items
 */
function paginator_accepts_collection(Collection $items): Paginator
{
    return new Paginator($items, 15);
}

/** @param LengthAwarePaginator<int, string> $paginator */
function length_aware_paginator_items(LengthAwarePaginator $paginator): void
{
    $_items = $paginator->items();
    /** @psalm-check-type-exact $_items = array<string> */

    $_collection = $paginator->getCollection();
    /** @psalm-check-type-exact $_collection = Collection<int, string> */
}

/** @param Paginator<int, string> $paginator */
function paginator_items(Paginator $paginator): void
{
    $_items = $paginator->items();
    /** @psalm-check-type-exact $_items = array<string> */

    $_collection = $paginator->getCollection();
    /** @psalm-check-type-exact $_collection = Collection<int, string> */
}

/** @param CursorPaginator<int, string> $paginator */
function cursor_paginator_items(CursorPaginator $paginator): void
{
    $_items = $paginator->items();
    /** @psalm-check-type-exact $_items = array<string> */

    $_collection = $paginator->getCollection();
    /** @psalm-check-type-exact $_collection = Collection<int, string> */
}
--EXPECT--
