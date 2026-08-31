--FILE--
<?php declare(strict_types=1);

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use App\Models\Customer;
use App\Models\Part;
use App\Models\WorkOrder;

/**
 * getCollection() narrows to Eloquent\Collection when TValue is a Model: paginate()/
 * simplePaginate()/cursorPaginate() on an Eloquent\Builder always hold an Eloquent
 * collection at runtime, so the conditional return proves the Eloquent-only surface
 * (e.g. load()) is safe to call without a manual cast.
 *
 * Customer has no custom collection, so this also pins the CustomCollectionHandler
 * decline path: the base Eloquent\Collection type must survive untouched.
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
 * WorkOrder declares a custom collection via #[CollectedBy(WorkOrderCollection::class)].
 * getCollection() on all three pagination styles should narrow to the custom collection,
 * and the narrowed type must expose WorkOrderCollection-only methods.
 */
function collected_by_paginate_get_collection_is_custom_collection(): void
{
    $_length = WorkOrder::query()->paginate()->getCollection();
    /** @psalm-check-type-exact $_length = App\Collections\WorkOrderCollection<int, WorkOrder> */
    $_length->completed();
    $_length->totalLaborHours();

    $_simple = WorkOrder::query()->simplePaginate()->getCollection();
    /** @psalm-check-type-exact $_simple = App\Collections\WorkOrderCollection<int, WorkOrder> */

    $_cursor = WorkOrder::query()->cursorPaginate()->getCollection();
    /** @psalm-check-type-exact $_cursor = App\Collections\WorkOrderCollection<int, WorkOrder> */
}

/**
 * Part declares a custom collection via a newCollection() override (the second detection
 * pattern, distinct from #[CollectedBy]) — getCollection() should narrow the same way.
 */
function new_collection_override_paginate_get_collection_is_custom_collection(): void
{
    $_length = Part::query()->paginate()->getCollection();
    /** @psalm-check-type-exact $_length = App\Collections\PartCollection<int, Part> */
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

/**
 * Customer has no custom collection, so CustomCollectionHandler must decline and let the
 * stub's own conditional return answer unmodified — including its inferred TKey. A plain
 * paginate() call can't distinguish "declined" from "answered with the same-looking base
 * Eloquent\Collection<int, Model>" (both infer TKey=int at that call site); a manually
 * constructed paginator with a single-element array literal infers a literal `0` key
 * instead, which only survives if the handler truly declined rather than reconstructing a
 * generic type with a hardcoded `int` key.
 *
 * This fixture is byte-identical to PaginatorGetCollectionKnownLimitationTest.phpt's
 * `manual_construction_holds_plain_support_collection_not_eloquent` (same assertion,
 * same construction) — kept here too for locality with the rest of this handler's
 * decline-path coverage, not because it exercises a codepath the sibling file misses.
 */
function no_custom_collection_manual_construction_decline_preserves_inferred_key(): void
{
    $paginator = new LengthAwarePaginator([new Customer()], 1, 15);

    $_collection = $paginator->getCollection();
    /** @psalm-check-type-exact $_collection = Illuminate\Database\Eloquent\Collection<0, Customer> */
}

/**
 * WorkOrder and Part declare DIFFERENT custom collections (WorkOrderCollection,
 * PartCollection) — a paginator whose TValue unions both is ambiguous, which
 * `hasMultipleModelTypes()` must catch. This pins that decline branch: without it,
 * `paginatorCollectionType()` would deterministically pick just one of the two model
 * classes out of the union and narrow to its collection, silently discarding the other.
 */
function union_of_models_with_different_custom_collections_stays_base_eloquent(): void
{
    /** @var LengthAwarePaginator<int, WorkOrder|Part> $paginator */
    $paginator = WorkOrder::query()->paginate();

    $_collection = $paginator->getCollection();
    /** @psalm-check-type-exact $_collection = Illuminate\Database\Eloquent\Collection<int, WorkOrder|Part> */
}

/**
 * On the ANSWER path (a registered custom collection is found), the receiver's own TKey
 * must survive, not get rebuilt as a hardcoded `int`. setCollection() re-templates $this
 * via @psalm-this-out from the passed Collection's own inferred key/value, so a non-int
 * key here (`collect(['a' => ...])`) is a real, reachable receiver template — the same
 * class of bug as `WorkOrder|null` losing the `null` or `WorkOrder&Arrayable` losing the
 * intersection.
 */
function custom_collection_answer_preserves_non_int_receiver_key(): void
{
    $paginator = WorkOrder::query()->paginate();
    $paginator->setCollection(collect(['a' => new WorkOrder()]));

    $_collection = $paginator->getCollection();
    /** @psalm-check-type-exact $_collection = App\Collections\WorkOrderCollection<'a', WorkOrder> */
}
?>
--EXPECTF--
