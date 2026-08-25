--FILE--
<?php declare(strict_types=1);

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use App\Models\Customer;

/**
 * getCollection() narrows to Eloquent\Collection when TValue is a Model: paginate()/
 * simplePaginate()/cursorPaginate() on an Eloquent\Builder always hold an Eloquent
 * collection at runtime, so the conditional return proves the Eloquent-only surface
 * (e.g. load()) is safe to call without a manual cast.
 */
function eloquent_paginate_get_collection_is_eloquent(): void
{
    $_length = Customer::query()->paginate()->getCollection();
    /** @psalm-check-type-exact $_length = Illuminate\Database\Eloquent\Collection<int, Customer> */
    $_length->load('primaryVehicle');

    $_simple = Customer::query()->simplePaginate()->getCollection();
    /** @psalm-check-type-exact $_simple = Illuminate\Database\Eloquent\Collection<int, Customer> */

    $_cursor = Customer::query()->cursorPaginate()->getCollection();
    /** @psalm-check-type-exact $_cursor = Illuminate\Database\Eloquent\Collection<int, Customer> */
}

/**
 * Query\Builder rows hydrate as stdClass (not a Model), so getCollection() stays on the
 * Support\Collection fallback branch.
 */
function query_builder_paginate_get_collection_stays_support(): void
{
    $_collection = DB::table('users')->paginate()->getCollection();
    /** @psalm-check-type-exact $_collection = Illuminate\Support\Collection<int, \stdClass> */
}

/** An array-valued TValue also stays on the Support\Collection fallback branch. */
function array_value_paginator_get_collection_stays_support(): void
{
    /** @var LengthAwarePaginator<int, array{id: int}> $paginator */
    $paginator = Customer::query()->paginate();

    $_collection = $paginator->getCollection();
    /** @psalm-check-type-exact $_collection = Illuminate\Support\Collection<int, array{id: int}> */
}
?>
--EXPECTF--
