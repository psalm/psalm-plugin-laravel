--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * KNOWN LIMITATION — getCollection()'s conditional return type keys off TValue, not the
 * runtime class of $this->items. A paginator built by hand (rather than via paginate()/
 * simplePaginate()/cursorPaginate() on an Eloquent\Builder) holds a plain Support\Collection
 * at runtime even when TValue is a Model, because the constructor and setCollection() never
 * upgrade the wrapped collection class. This test pins the CURRENT (imprecise) output — the
 * stub resolves the Model branch — so it flips loudly if a future provenance-aware handler
 * narrows on the actual constructor/setCollection() argument instead of TValue.
 *
 * See the docblock on AbstractPaginator::getCollection() in
 * stubs/common/Pagination/Pagination.phpstub for the full trade-off.
 *
 * Same provenance family: `through()` transforms the wrapped collection IN PLACE
 * (`AbstractPaginator::through()` calls `$this->items->transform($callback)`, see
 * AbstractPaginator.php:363 in vendor/laravel/framework) — the wrapped collection object
 * never changes class. `Part::query()->paginate()->through(fn(Part $p): WorkOrder =>
 * new WorkOrder())->getCollection()` therefore types as `WorkOrderCollection` (TValue is
 * now WorkOrder) while staying a `PartCollection` at runtime.
 */
function manual_construction_holds_plain_support_collection_not_eloquent(): void
{
    $paginator = new LengthAwarePaginator([new Customer()], 1, 15);

    $_collection = $paginator->getCollection();
    // Key is the literal `0` from the single-element array literal, not `int` — incidental to
    // this fixture, not part of the limitation being pinned.
    /** @psalm-check-type-exact $_collection = Illuminate\Database\Eloquent\Collection<0, Customer> */
}

function set_collection_with_plain_collection_of_models_holds_plain_support_collection(): void
{
    /** @var LengthAwarePaginator<int, \stdClass> $paginator */
    $paginator = Customer::query()->paginate();

    $paginator->setCollection(collect([new Customer()]));

    $_collection = $paginator->getCollection();
    /** @psalm-check-type-exact $_collection = Illuminate\Database\Eloquent\Collection<0, Customer> */
}

/**
 * Not a limitation any more: `Model::__toString()` exists (CanBeEscapedWhenCastToString), so Psalm
 * cannot rule out a plain `string` TValue being a Model and evaluates BOTH branches of a bare
 * `TValue is Model` conditional. Psalm 7.0.0-rc6 narrows TValue inside the Model branch, which would
 * leak a spurious `Eloquent\Collection<int, Model>` into the result; the stub's outer
 * `TValue is object` arm decides the string case up front, so it resolves to the plain collection.
 *
 * @param LengthAwarePaginator<int, string> $paginator
 */
function string_value_paginator_get_collection_is_plain_support_collection(LengthAwarePaginator $paginator): void
{
    $_collection = $paginator->getCollection();
    /** @psalm-check-type-exact $_collection = Illuminate\Support\Collection<int, string> */
}
?>
--EXPECTF--
