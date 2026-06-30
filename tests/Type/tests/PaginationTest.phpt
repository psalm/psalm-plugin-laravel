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

// fragment() is get-or-set: null arg reads the fragment (string|null), any other
// arg sets it and returns the paginator (carrying its templates) for chaining.

/** @param Paginator<int, string> $paginator */
function paginator_fragment(Paginator $paginator): void
{
    $_get = $paginator->fragment();
    /** @psalm-check-type-exact $_get = string|null */

    $_set = $paginator->fragment('foo');
    /** @psalm-check-type-exact $_set = Paginator<int, string>&static */

    // Setter stays a paginator, so the next chained call resolves.
    $_chained = $paginator->fragment('foo')->items();
    /** @psalm-check-type-exact $_chained = array<string> */
}

/** @param CursorPaginator<int, string> $paginator */
function cursor_paginator_fragment(CursorPaginator $paginator): void
{
    $_get = $paginator->fragment();
    /** @psalm-check-type-exact $_get = string|null */

    $_set = $paginator->fragment('foo');
    /** @psalm-check-type-exact $_set = CursorPaginator<int, string>&static */
}
--EXPECTF--
