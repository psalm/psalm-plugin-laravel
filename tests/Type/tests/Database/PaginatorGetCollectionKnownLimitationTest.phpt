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
 * KNOWN LIMITATION — Model::__toString() exists (CanBeEscapedWhenCastToString), so
 * `TValue is Model` is undecidable for a plain `string` TValue and Psalm evaluates BOTH
 * conditional branches, yielding their union. Pre-existing house behaviour: Eloquent\Collection
 * ::map() has the identical trade-off for the same reason.
 *
 * @param LengthAwarePaginator<int, string> $paginator
 */
function string_value_paginator_get_collection_is_union(LengthAwarePaginator $paginator): void
{
    $_collection = $paginator->getCollection();
    /** @psalm-check-type-exact $_collection = Illuminate\Support\Collection<int, string>|Illuminate\Database\Eloquent\Collection<int, string> */
}
?>
--EXPECTF--
