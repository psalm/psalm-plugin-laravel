--FILE--
<?php declare(strict_types=1);

use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

// fragment() is get-or-set: a null arg reads the fragment (string|null), any other arg
// sets it and returns the paginator (carrying its templates) for chaining.

/**
 * The reported shape (#1925): the setter result must stay a paginator, not `$this|string|null`.
 *
 * @param LengthAwarePaginator<int, stdClass> $p
 */
function reported_chain(LengthAwarePaginator $p): void
{
    $_html = $p->fragment('members')->links();
    /** @psalm-check-type-exact $_html = Illuminate\Contracts\Support\Htmlable */
}

/** @param LengthAwarePaginator<int, string> $paginator */
function length_aware_fragment(LengthAwarePaginator $paginator): void
{
    $_get = $paginator->fragment();
    /** @psalm-check-type-exact $_get = string|null */

    $_explicit_null = $paginator->fragment(null);
    /** @psalm-check-type-exact $_explicit_null = string|null */

    $_set = $paginator->fragment('foo');
    /** @psalm-check-type-exact $_set = LengthAwarePaginator<int, string>&static */

    $_chained = $paginator->fragment('foo')->items();
    /** @psalm-check-type-exact $_chained = array<int, string> */
}

/** @param Paginator<int, string> $paginator */
function simple_paginator_fragment(Paginator $paginator): void
{
    $_get = $paginator->fragment();
    /** @psalm-check-type-exact $_get = string|null */

    $_set = $paginator->fragment('foo');
    /** @psalm-check-type-exact $_set = Paginator<int, string>&static */

    $_chained = $paginator->fragment('foo')->items();
    /** @psalm-check-type-exact $_chained = array<int, string> */
}

/** @param CursorPaginator<int, string> $paginator */
function cursor_paginator_fragment(CursorPaginator $paginator): void
{
    $_get = $paginator->fragment();
    /** @psalm-check-type-exact $_get = string|null */

    $_set = $paginator->fragment('foo');
    /** @psalm-check-type-exact $_set = CursorPaginator<int, string>&static */

    $_chained = $paginator->fragment('foo')->items();
    /** @psalm-check-type-exact $_chained = array<int, string> */
}

/** @param LengthAwarePaginatorContract<int, string> $paginator */
function length_aware_contract_fragment(LengthAwarePaginatorContract $paginator): void
{
    $_get = $paginator->fragment();
    /** @psalm-check-type-exact $_get = string|null */

    $_set = $paginator->fragment('foo');
    /** @psalm-check-type-exact $_set = LengthAwarePaginatorContract<int, string>&static */

    $_chained = $paginator->fragment('foo')->items();
    /** @psalm-check-type-exact $_chained = array<int, string> */
}

/** @param CursorPaginatorContract<int, string> $paginator */
function cursor_contract_fragment(CursorPaginatorContract $paginator): void
{
    $_get = $paginator->fragment();
    /** @psalm-check-type-exact $_get = string|null */

    $_set = $paginator->fragment('foo');
    /** @psalm-check-type-exact $_set = CursorPaginatorContract<int, string>&static */
}

/**
 * A userland subclass keeps its own class through the chain. `getIterator()` is overridden only
 * because inheriting it unchanged reports an unrelated ImmutableDependency on every paginator subclass.
 *
 * @extends LengthAwarePaginator<int, string>
 */
class FragmentSubclassPaginator extends LengthAwarePaginator
{
    #[\Override]
    public function getIterator(): Traversable
    {
        return parent::getIterator();
    }
}

function subclass_fragment(FragmentSubclassPaginator $paginator): void
{
    $_set = $paginator->fragment('foo');
    /** @psalm-check-type-exact $_set = FragmentSubclassPaginator&static */

    $_get = $paginator->fragment();
    /** @psalm-check-type-exact $_get = string|null */
}
?>
--EXPECTF--
