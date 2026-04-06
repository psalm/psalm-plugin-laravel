--FILE--
<?php declare(strict_types=1);

namespace App\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * HigherOrderCollectionProxy: $collection->each->method() pattern.
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/511
 */
final class HigherOrderCollectionProxyTest
{
    /**
     * The most common use case: ->each->delete() for side effects.
     * @param EloquentCollection<int, Customer> $users
     */
    public function eachDeleteEloquent(EloquentCollection $users): void
    {
        $_result = $users->each->delete();
        /** @psalm-check-type-exact $_result = EloquentCollection<int, Customer> */
    }

    /**
     * each returns the same collection (passthrough).
     * @param Collection<int, Customer> $users
     */
    public function eachReturnType(Collection $users): void
    {
        $_result = $users->each->delete();
        /** @psalm-check-type-exact $_result = Collection<int, Customer> */
    }

    /**
     * LazyCollection also supports the proxy pattern.
     * @param LazyCollection<int, Customer> $users
     */
    public function eachLazyCollection(LazyCollection $users): void
    {
        $_result = $users->each->delete();
        /** @psalm-check-type-exact $_result = LazyCollection<int, Customer> */
    }

    /**
     * map returns Collection<TKey, TMethodReturn>.
     * @param Collection<int, Customer> $users
     */
    public function mapReturnType(Collection $users): void
    {
        $_result = $users->map->getKey();
        /** @psalm-check-type-exact $_result = Collection<int, int|string> */
    }

    /**
     * filter returns the same collection (passthrough).
     * @param Collection<int, Customer> $users
     */
    public function filterReturnType(Collection $users): void
    {
        $_result = $users->filter->isClean();
        /** @psalm-check-type-exact $_result = Collection<int, Customer> */
    }

    /**
     * contains returns bool.
     * @param Collection<int, Customer> $users
     */
    public function containsReturnType(Collection $users): void
    {
        $_result = $users->contains->trashed();
        /** @psalm-check-type-exact $_result = bool */
    }

    /**
     * every returns bool.
     * @param Collection<int, Customer> $users
     */
    public function everyReturnType(Collection $users): void
    {
        $_result = $users->every->getKey();
        /** @psalm-check-type-exact $_result = bool */
    }

    /**
     * some (alias for contains) returns bool.
     * @param Collection<int, Customer> $users
     */
    public function someReturnType(Collection $users): void
    {
        $_result = $users->some->trashed();
        /** @psalm-check-type-exact $_result = bool */
    }

    /**
     * sum returns int|float.
     * @param Collection<int, Customer> $users
     */
    public function sumReturnType(Collection $users): void
    {
        $_result = $users->sum->getKey();
        /** @psalm-check-type-exact $_result = float|int */
    }

    /**
     * avg returns float|null.
     * @param Collection<int, Customer> $users
     */
    public function avgReturnType(Collection $users): void
    {
        $_result = $users->avg->getKey();
        /** @psalm-check-type-exact $_result = float|null */
    }

    /**
     * reject returns the same collection (passthrough).
     * @param Collection<int, Customer> $users
     */
    public function rejectReturnType(Collection $users): void
    {
        $_result = $users->reject->trashed();
        /** @psalm-check-type-exact $_result = Collection<int, Customer> */
    }

    /**
     * sortBy returns the same collection (passthrough).
     * @param Collection<int, Customer> $users
     */
    public function sortByReturnType(Collection $users): void
    {
        $_result = $users->sortBy->getKey();
        /** @psalm-check-type-exact $_result = Collection<int, Customer> */
    }

    /**
     * keyBy returns collection with array-key keys.
     * @param Collection<int, Customer> $users
     */
    public function keyByReturnType(Collection $users): void
    {
        $_result = $users->keyBy->getKey();
        /** @psalm-check-type-exact $_result = Collection<array-key, Customer> */
    }

    /**
     * first returns TValue|null.
     * @param Collection<int, Customer> $users
     */
    public function firstReturnType(Collection $users): void
    {
        $_result = $users->first->trashed();
        /** @psalm-check-type-exact $_result = Customer|null */
    }

    /**
     * last returns TValue|null.
     * @param Collection<int, Customer> $users
     */
    public function lastReturnType(Collection $users): void
    {
        $_result = $users->last->trashed();
        /** @psalm-check-type-exact $_result = Customer|null */
    }

    /**
     * unique returns the same collection (passthrough).
     * @param Collection<int, Customer> $users
     */
    public function uniqueReturnType(Collection $users): void
    {
        $_result = $users->unique->getKey();
        /** @psalm-check-type-exact $_result = Collection<int, Customer> */
    }

    /**
     * flatMap returns Collection<int, mixed>.
     * @param Collection<int, Customer> $users
     */
    public function flatMapReturnType(Collection $users): void
    {
        $_result = $users->flatMap->getKey();
        /** @psalm-check-type-exact $_result = Collection<int, mixed> */
    }

    /**
     * groupBy returns nested collection with array-key outer keys.
     * @param Collection<int, Customer> $users
     */
    public function groupByReturnType(Collection $users): void
    {
        $_result = $users->groupBy->getKey();
        /** @psalm-check-type-exact $_result = Collection<array-key, Collection<int, Customer>> */
    }

    /**
     * groupBy with string-keyed collection re-indexes inner keys to int.
     * @param Collection<string, Customer> $users
     */
    public function groupByStringKeysReturnType(Collection $users): void
    {
        $_result = $users->groupBy->getKey();
        /** @psalm-check-type-exact $_result = Collection<array-key, Collection<int, Customer>> */
    }

    /**
     * partition returns nested collection.
     * @param Collection<int, Customer> $users
     */
    public function partitionReturnType(Collection $users): void
    {
        $_result = $users->partition->trashed();
        /** @psalm-check-type-exact $_result = Collection<int, Collection<int, Customer>> */
    }
}
?>
--EXPECT--
