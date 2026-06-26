--FILE--
<?php declare(strict_types=1);

use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/** @param LengthAwarePaginator<int, string> $paginator */
function length_aware_through_inline(LengthAwarePaginator $paginator): LengthAwarePaginator
{
    // Inline return: value template remapped via @return static<...>.
    return $paginator->through(fn (string $value): int => (int) $value);
}

/** @param LengthAwarePaginator<int, string> $paginator */
function length_aware_through_remaps_value(LengthAwarePaginator $paginator): void
{
    $_mapped = $paginator->through(fn (string $value): array => [$value, (int) $value]);
    /** @psalm-check-type-exact $_mapped = LengthAwarePaginator<int, list{string, int}>&static */
}

/** @param Paginator<int, string> $paginator */
function paginator_through_remaps_value(Paginator $paginator): void
{
    $_mapped = $paginator->through(fn (string $value): int => (int) $value);
    /** @psalm-check-type-exact $_mapped = Paginator<int, int>&static */
}

/** @param CursorPaginator<int, string> $paginator */
function cursor_through_remaps_value(CursorPaginator $paginator): void
{
    $_mapped = $paginator->through(fn (string $value): int => (int) $value);
    /** @psalm-check-type-exact $_mapped = CursorPaginator<int, int>&static */
}

/** @param LengthAwarePaginator<int, string> $paginator */
function length_aware_through_this_out(LengthAwarePaginator $paginator): void
{
    // Assigned-variable case: @psalm-this-out mutates the receiver in place.
    $paginator->through(fn (string $value): int => (int) $value);
    $_collection = $paginator->getCollection();
    /** @psalm-check-type-exact $_collection = Illuminate\Support\Collection<int, int> */
}

--EXPECTF--
