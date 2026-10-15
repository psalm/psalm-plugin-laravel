--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;
use App\Models\MechanicSpecialization;
use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Contracts\Pagination\Paginator as PaginatorContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

// Laravel declares TValue as @template-covariant on all eight types; TKey stays invariant.

/** @param LengthAwarePaginator<int, Model> $_p */
function length_aware_takes_model(LengthAwarePaginator $_p): void {}

/** @param Paginator<int, Model> $_p */
function simple_takes_model(Paginator $_p): void {}

/** @param CursorPaginator<int, Model> $_p */
function cursor_takes_model(CursorPaginator $_p): void {}

/** @param LengthAwarePaginatorContract<int, Model> $_p */
function length_aware_contract_takes_model(LengthAwarePaginatorContract $_p): void {}

/** @param PaginatorContract<int, Model> $_p */
function simple_contract_takes_model(PaginatorContract $_p): void {}

/** @param CursorPaginatorContract<int, Model> $_p */
function cursor_contract_takes_model(CursorPaginatorContract $_p): void {}

/** @param LengthAwarePaginator<int, MechanicSpecialization> $_p */
function length_aware_takes_specialization(LengthAwarePaginator $_p): void {}

/**
 * @param LengthAwarePaginator<int, MechanicSpecialization> $lengthAware
 * @param Paginator<int, MechanicSpecialization> $simple
 * @param CursorPaginator<int, MechanicSpecialization> $cursor
 * @param LengthAwarePaginatorContract<int, MechanicSpecialization> $lengthAwareContract
 * @param PaginatorContract<int, MechanicSpecialization> $simpleContract
 * @param CursorPaginatorContract<int, MechanicSpecialization> $cursorContract
 */
function widen_every_paginator(
    LengthAwarePaginator $lengthAware,
    Paginator $simple,
    CursorPaginator $cursor,
    LengthAwarePaginatorContract $lengthAwareContract,
    PaginatorContract $simpleContract,
    CursorPaginatorContract $cursorContract,
): void {
    length_aware_takes_model($lengthAware);
    simple_takes_model($simple);
    cursor_takes_model($cursor);
    length_aware_contract_takes_model($lengthAware);
    length_aware_contract_takes_model($lengthAwareContract);
    simple_contract_takes_model($simple);
    simple_contract_takes_model($lengthAware);
    simple_contract_takes_model($lengthAwareContract);
    simple_contract_takes_model($simpleContract);
    cursor_contract_takes_model($cursor);
    cursor_contract_takes_model($cursorContract);
}

/** @param LengthAwarePaginator<int, Mechanic> $paginator */
function unrelated_model_is_still_rejected(LengthAwarePaginator $paginator): void
{
    length_aware_takes_specialization($paginator);
}

/**
 * @param LengthAwarePaginator<int, MechanicSpecialization> $lengthAware
 * @param Paginator<int, MechanicSpecialization> $simple
 * @param CursorPaginator<int, MechanicSpecialization> $cursor
 */
function through_with_typed_closure(LengthAwarePaginator $lengthAware, Paginator $simple, CursorPaginator $cursor): void
{
    $_lengthAware = $lengthAware->through(fn (MechanicSpecialization $s): int => \strlen($s->getTable()));
    /** @psalm-check-type-exact $_lengthAware = LengthAwarePaginator<int, int<0, max>>&static */

    $_simple = $simple->through(fn (MechanicSpecialization $s): int => \strlen($s->getTable()));
    /** @psalm-check-type-exact $_simple = Paginator<int, int<0, max>>&static */

    $_cursor = $cursor->through(fn (MechanicSpecialization $s): int => \strlen($s->getTable()));
    /** @psalm-check-type-exact $_cursor = CursorPaginator<int, int<0, max>>&static */
}
--EXPECTF--
InvalidArgument on line %d: Argument 1 of length_aware_takes_specialization expects Illuminate\Pagination\LengthAwarePaginator<int, App\Models\MechanicSpecialization>, but Illuminate\Pagination\LengthAwarePaginator<int, App\Models\Mechanic> provided
