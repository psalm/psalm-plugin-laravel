--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

class Customer extends Model
{
    use SoftDeletes;

    public function getPrice(): float
    {
        return 0.0;
    }
}


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
     * map on LazyCollection returns LazyCollection (LazyCollection::map() is static).
     * @param LazyCollection<int, Customer> $users
     */
    public function mapLazyCollectionReturnType(LazyCollection $users): void
    {
        $_result = $users->map->getKey();
        /** @psalm-check-type-exact $_result = LazyCollection<int, int|string> */
    }

    /**
     * map on EloquentCollection falls back to Support\Collection when the mapped values
     * are not Model instances — mirrors EloquentCollection::map() @psalm-return conditional.
     * @param EloquentCollection<int, Customer> $users
     */
    public function mapEloquentCollectionReturnType(EloquentCollection $users): void
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
     * sum always returns int|float — the + accumulator produces int|float regardless of
     * what the callee returns. String-returning methods (e.g. getKey() on UUID models)
     * would cause a TypeError at runtime, so we don't narrow to the callee type.
     * @param Collection<int, Customer> $users
     */
    public function sumReturnType(Collection $users): void
    {
        $_result = $users->sum->getKey();
        /** @psalm-check-type-exact $_result = float|int */
    }

    /**
     * sum with a purely numeric callee (float) — still int|float, not narrowed to float.
     * Guards against a future regression where the callee type leaks into the sum return.
     * @param Collection<int, Customer> $users
     */
    public function sumWithNumericCalleeReturnType(Collection $users): void
    {
        $_result = $users->sum->getPrice();
        /** @psalm-check-type-exact $_result = float|int */
    }

    /**
     * avg returns float|int|null (matches Laravel's actual return type).
     * @param Collection<int, Customer> $users
     */
    public function avgReturnType(Collection $users): void
    {
        $_result = $users->avg->getKey();
        /** @psalm-check-type-exact $_result = float|int|null */
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
     * flatMap returns Collection<array-key, mixed> (keys are controlled by the callback).
     * @param Collection<int, Customer> $users
     */
    public function flatMapReturnType(Collection $users): void
    {
        $_result = $users->flatMap->getKey();
        /** @psalm-check-type-exact $_result = Collection<array-key, mixed> */
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


    /**
     * max returns the called method result|null — null for empty collections (reduce() with null initial value).
     * @param Collection<int, Customer> $users
     */
    public function maxReturnType(Collection $users): void
    {
        $_result = $users->max->getKey();
        /** @psalm-check-type-exact $_result = int|string|null */
    }

    /**
     * min returns the called method result|null — null for empty collections (reduce() with null initial value).
     * @param Collection<int, Customer> $users
     */
    public function minReturnType(Collection $users): void
    {
        $_result = $users->min->getKey();
        /** @psalm-check-type-exact $_result = int|string|null */
    }

    /**
     * max with a numeric callee — callee type is preserved with null added for empty collections.
     * Contrasts with sum: max preserves the callee return type; sum always returns int|float.
     * @param Collection<int, Customer> $users
     */
    public function maxWithNumericCalleeReturnType(Collection $users): void
    {
        $_result = $users->max->getPrice();
        /** @psalm-check-type-exact $_result = float|null */
    }

    /**
     * min with a numeric callee — same semantics as max (callee type preserved, null added).
     * @param Collection<int, Customer> $users
     */
    public function minWithNumericCalleeReturnType(Collection $users): void
    {
        $_result = $users->min->getPrice();
        /** @psalm-check-type-exact $_result = float|null */
    }

    /**
     * average returns float|int|null.
     * @param Collection<int, Customer> $users
     */
    public function averageReturnType(Collection $users): void
    {
        $_result = $users->average->getKey();
        /** @psalm-check-type-exact $_result = float|int|null */
    }

    /**
     * doesntContain returns bool.
     * @param Collection<int, Customer> $users
     */
    public function doesntContainReturnType(Collection $users): void
    {
        $_result = $users->doesntContain->trashed();
        /** @psalm-check-type-exact $_result = bool */
    }

    /**
     * hasSole returns bool.
     * @param Collection<int, Customer> $users
     */
    public function hasSoleReturnType(Collection $users): void
    {
        $_result = $users->hasSole->trashed();
        /** @psalm-check-type-exact $_result = bool */
    }

    /**
     * EloquentCollection partition outer bucket falls back to base Collection.
     * EloquentCollection::partition() calls parent::partition()->toBase().
     * @param EloquentCollection<int, Customer> $users
     */
    public function partitionEloquentReturnType(EloquentCollection $users): void
    {
        $_result = $users->partition->trashed();
        /** @psalm-check-type-exact $_result = Collection<int, EloquentCollection<int, Customer>> */
    }

    /**
     * flatMap on LazyCollection preserves the LazyCollection type.
     * @param LazyCollection<int, Customer> $users
     */
    public function flatMapLazyCollectionReturnType(LazyCollection $users): void
    {
        $_result = $users->flatMap->getKey();
        /** @psalm-check-type-exact $_result = LazyCollection<array-key, mixed> */
    }

    /**
     * hasMany returns bool (boolean proxy, shares the BOOLEAN_METHODS path with contains/every).
     * @param Collection<int, Customer> $users
     */
    public function hasManyReturnType(Collection $users): void
    {
        $_result = $users->hasMany->trashed();
        /** @psalm-check-type-exact $_result = bool */
    }

    /**
     * percentage returns float|null — percentage() calls round() which always returns float.
     * Unlike avg/average which can return int via integer division, percentage never returns int.
     * @param Collection<int, Customer> $users
     */
    public function percentageReturnType(Collection $users): void
    {
        $_result = $users->percentage->getKey();
        /** @psalm-check-type-exact $_result = float|null */
    }

    /**
     * sortByDesc->method() chaining must not produce InvalidMethodCall.
     * Previously Psalm inferred int via @mixin TValue and failed on ->values() on int.
     * @param EloquentCollection<int, Customer> $members
     */
    public function sortByDescChaining(EloquentCollection $members): void
    {
        $_result = $members->sortByDesc->getKey();
        /** @psalm-check-type-exact $_result = EloquentCollection<int, Customer> */
        $_result->values();
    }

    /**
     * Proxy method called with arguments must not emit TooManyArguments.
     * The handler provides a variadic-mixed signature for all proxy method calls
     * so that $collection->each->update(['key' => 'val']) works without errors.
     * @param EloquentCollection<int, Customer> $users
     */
    public function proxyMethodWithArguments(EloquentCollection $users): void
    {
        // Multi-arg proxy call — must not emit TooManyArguments
        $users->each->update(['status' => 'active', 'updated_at' => null]);
    }
}

?>
--EXPECTF--
