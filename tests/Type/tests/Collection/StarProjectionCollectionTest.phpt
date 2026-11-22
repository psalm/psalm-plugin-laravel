--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\PsalmStarProjectionCapability::skipUnlessSupported();
--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\StarProjectionCollection;

use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Collection handlers answer a collection over the receiver's type arguments. Built from the bare
 * arguments, a `*` became its bound and the answer accepted writes the receiver rejects (#2380). A `*`
 * stays exactly where the receiver's own argument passes through; a slot the handler computes is a
 * known type, even when it equals the bound (`mixed`, `array-key`).
 *
 * Skipped on a Psalm without star projections.
 */

/**
 * @template TValue
 * @template TKey of array-key
 * @extends Collection<TKey, TValue>
 */
final class KeyLast extends Collection {}

/**
 * @template TKey of array-key
 * @template TValue
 * @template TMeta
 * @extends Collection<TKey, TValue>
 */
final class WithMeta extends Collection {}

/** @param Collection<array-key, Customer> $_collection */
function takes_array_key_collection(Collection $_collection): void {}

/** @param Collection<int, Collection<array-key, Customer>> $_collection */
function takes_partitioned(Collection $_collection): void {}

/**
 * `filter()` / `whereNotNull()` narrow the value and hand the key through.
 *
 * @param Collection<*, Customer|null> $collection
 * @param Collection<array-key, Vehicle|null>|Collection<*, Customer|null> $union
 * @param Collection<*, Customer|null>|null $nullable
 */
function narrowing(Collection $collection, Collection $union, ?Collection $nullable): void
{
    $_filter = $collection->filter(static fn (?Customer $customer): bool => $customer !== null);
    /** @psalm-check-type-exact $_filter = Collection<*, Customer> */

    $_notNull = $collection->whereNotNull();
    /** @psalm-check-type-exact $_notNull = Collection<*, Customer> */

    $_lateStatic = $collection->reverse()->whereNotNull();
    /** @psalm-check-type-exact $_lateStatic = Collection<*, Customer>&static */

    // The provider answers per atomic: the `*` branch is found by its own arguments, not as the first of its class.
    $_union = $union->whereNotNull();
    /** @psalm-check-type-exact $_union = Collection<*, Customer>|Collection<array-key, Vehicle> */

    $_nullsafe = $nullable?->whereNotNull();
    /** @psalm-check-type-exact $_nullsafe = Collection<*, Customer>|null */
}

/**
 * @param Collection<*, Customer> $starKey
 * @param Collection<int, *> $starValue
 */
function higher_order_proxy(Collection $starKey, Collection $starValue): void
{
    $_each = $starKey->each->touch();
    /** @psalm-check-type-exact $_each = Collection<*, Customer> */

    $_filter = $starKey->filter->isDirty();
    /** @psalm-check-type-exact $_filter = Collection<*, Customer> */

    $_fetched = $starKey->filter->id;
    /** @psalm-check-type-exact $_fetched = Collection<*, Customer> */

    // The outer collection is new; the buckets are the receiver's collection and carry its `*`.
    $_partition = $starKey->partition->isDirty();
    /** @psalm-check-type-exact $_partition = Collection<int, Collection<*, Customer>> */

    $_map = $starKey->map->isDirty();
    /** @psalm-check-type-exact $_map = Collection<*, bool> */

    $_keyBy = $starKey->keyBy->getKey();
    /** @psalm-check-type-exact $_keyBy = Collection<array-key, Customer> */

    $_valueFilter = $starValue->filter->isDirty();
    /** @psalm-check-type-exact $_valueFilter = Collection<int, *> */

    $_valuePartition = $starValue->partition->isDirty();
    /** @psalm-check-type-exact $_valuePartition = Collection<int, Collection<int, *>> */

    $_valueGroupBy = $starValue->groupBy->isDirty();
    /** @psalm-check-type-exact $_valueGroupBy = Collection<array-key, Collection<int, *>> */

    $_valueKeyBy = $starValue->keyBy->isDirty();
    /** @psalm-check-type-exact $_valueKeyBy = Collection<array-key, *> */

    $_valueMap = $starValue->map->isDirty();
    /** @psalm-check-type-exact $_valueMap = Collection<int, mixed> */
}

/**
 * A `*` stays in its slot of the receiver's own class; another class or arity answers a new object, and so does an
 * answer that recomputes a slot of a class whose layout is not proven `<TKey, TValue>`.
 *
 * @param EloquentCollection<*, Customer> $eloquent
 * @param KeyLast<Customer, *> $keyLast
 * @param KeyLast<*, int> $valueFirst
 * @param WithMeta<*, Customer, string> $withMeta
 * @param Collection<*, Customer>|EloquentCollection<int, Customer> $union
 */
function receiver_matrix(
    EloquentCollection $eloquent,
    KeyLast $keyLast,
    KeyLast $valueFirst,
    WithMeta $withMeta,
    Collection $union,
): void {
    $_filter = $eloquent->filter->isDirty();
    /** @psalm-check-type-exact $_filter = EloquentCollection<*, Customer> */

    $_partition = $eloquent->partition->isDirty();
    /** @psalm-check-type-exact $_partition = Collection<int, EloquentCollection<*, Customer>> */

    $_map = $eloquent->map->isDirty();
    /** @psalm-check-type-exact $_map = Collection<array-key, bool> */

    $_keyLast = $keyLast->filter->isDirty();
    /** @psalm-check-type-exact $_keyLast = KeyLast<Customer, *> */

    $_keyLastKeyBy = $keyLast->keyBy->getKey();
    /** @psalm-check-type-exact $_keyLastKeyBy = KeyLast<array-key, array-key> */

    $_valueFirstMap = $valueFirst->map->isDirty();
    /** @psalm-check-type-exact $_valueFirstMap = KeyLast<mixed, mixed> */

    $_withMeta = $withMeta->filter->isDirty();
    /** @psalm-check-type-exact $_withMeta = WithMeta<array-key, Customer> */

    $_union = $union->filter->isDirty();
    /** @psalm-check-type-exact $_union = Collection<*, Customer>|EloquentCollection<int, Customer> */
}

/**
 * The `InvalidArgument ... expects never` on both calls is Psalm's (a callable union parameter that
 * mentions a `*` template), not the handler's.
 *
 * @param Collection<int, *> $collection
 */
function direct_key_by_and_group_by(Collection $collection): void
{
    $_keyBy = $collection->keyBy(static fn (mixed $item): mixed => $item);
    /** @psalm-check-type-exact $_keyBy = Collection<array-key, *> */

    $_groupBy = $collection->groupBy(static fn (mixed $item): mixed => $item);
    /** @psalm-check-type-exact $_groupBy = Collection<array-key, Collection<int, *>> */
}

/**
 * @param Collection<*, Customer|null> $nullable
 * @param Collection<*, Customer> $collection
 */
function star_writes_are_rejected(Collection $nullable, Collection $collection): void
{
    takes_array_key_collection($nullable->whereNotNull());
    takes_array_key_collection($collection->each->touch());
    takes_partitioned($collection->partition->isDirty());
    takes_array_key_collection($collection->partition->isDirty()->firstOrFail());
}
--EXPECTF--
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::keyBy expects never, but Closure[pure](mixed):mixed provided
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::groupBy expects never, but Closure[pure](mixed):mixed provided
ArgumentTypeCoercion on line %d: Argument 1 of App\Sandbox\StarProjectionCollection\takes_array_key_collection expects Illuminate\Support\Collection<array-key, App\Models\Customer>, but parent type Illuminate\Support\Collection<*, App\Models\Customer> provided
ArgumentTypeCoercion on line %d: Argument 1 of App\Sandbox\StarProjectionCollection\takes_array_key_collection expects Illuminate\Support\Collection<array-key, App\Models\Customer>, but parent type Illuminate\Support\Collection<*, App\Models\Customer> provided
ArgumentTypeCoercion on line %d: Argument 1 of App\Sandbox\StarProjectionCollection\takes_partitioned expects Illuminate\Support\Collection<int, Illuminate\Support\Collection<array-key, App\Models\Customer>>, but parent type Illuminate\Support\Collection<int, Illuminate\Support\Collection<*, App\Models\Customer>> provided
ArgumentTypeCoercion on line %d: Argument 1 of App\Sandbox\StarProjectionCollection\takes_array_key_collection expects Illuminate\Support\Collection<array-key, App\Models\Customer>, but parent type Illuminate\Support\Collection<*, App\Models\Customer> provided
