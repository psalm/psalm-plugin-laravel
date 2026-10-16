--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;
use App\Models\MechanicSpecialization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * KNOWN LIMITATION — paginators are covariant in TValue like Laravel, so a helper typed
 * `LengthAwarePaginator<int, Model>` accepts a `LengthAwarePaginator<int, MechanicSpecialization>`
 * and can replace the items in place. getCollection() returns the live wrapped collection, and
 * through()/setCollection() mutate the same object; `@psalm-this-out` only rebinds the callee's
 * own receiver variable, never the caller's alias. The caller keeps the narrow type. These
 * tests pin that nothing is reported.
 *
 * See docs/contributing/decisions.md, "Paginators are covariant in `TValue`".
 *
 * @param LengthAwarePaginator<int, Model> $paginator
 */
function widen_via_live_collection(LengthAwarePaginator $paginator): void
{
    $paginator->getCollection()->transform(static fn (Model $_): Model => new Mechanic());
}

/** @param LengthAwarePaginator<int, Model> $paginator */
function widen_via_through(LengthAwarePaginator $paginator): void
{
    $paginator->through(static fn (Model $_): Model => new Mechanic());
}

/** @param LengthAwarePaginator<int, Model> $paginator */
function widen_via_set_collection(LengthAwarePaginator $paginator): void
{
    $paginator->setCollection(collect([new Mechanic()]));
}

/** @param LengthAwarePaginator<int, MechanicSpecialization> $paginator */
function caller_still_sees_narrow_type(LengthAwarePaginator $paginator): void
{
    widen_via_live_collection($paginator);
    widen_via_through($paginator);
    widen_via_set_collection($paginator);

    $_items = $paginator->items();
    /** @psalm-check-type-exact $_items = array<int, MechanicSpecialization> */
}
--EXPECTF--
