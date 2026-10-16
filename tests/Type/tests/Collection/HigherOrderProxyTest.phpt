--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\HigherOrderCollectionProxy;
use Illuminate\Support\LazyCollection;

class User extends Model
{
    public function sendWelcomeEmail(): bool
    {
        return true;
    }
}

// Collection — integer keys
/** @param Collection<int, User> $collection */
function testCollectionProxyTypes(Collection $collection): void
{
    /** @psalm-check-type-exact $_mapProxy = HigherOrderCollectionProxy<'map', User, Collection<int, User>> */
    $_mapProxy = $collection->map;

    /** @psalm-check-type-exact $_filterProxy = HigherOrderCollectionProxy<'filter', User, Collection<int, User>> */
    $_filterProxy = $collection->filter;

    /** @psalm-check-type-exact $_rejectProxy = HigherOrderCollectionProxy<'reject', User, Collection<int, User>> */
    $_rejectProxy = $collection->reject;
}

// Collection — string keys (TKey must propagate through the stub)
/** @param Collection<string, User> $collection */
function testCollectionStringKeyedProxy(Collection $collection): void
{
    /** @psalm-check-type-exact $_proxy = HigherOrderCollectionProxy<'map', User, Collection<string, User>> */
    $_proxy = $collection->map;
}

// Method calls on the proxy return the original collection type (passthrough behaviour).
// each() returns the same concrete collection — Collection, LazyCollection, or EloquentCollection.
// Previously typed as bool via @mixin TValue (incorrect) or Enumerable (imprecise).
/** @param Collection<int, User> $collection */
function testMethodCallViaProxy(Collection $collection): Collection
{
    /** @psalm-check-type-exact $result = Collection<int, User> */
    $result = $collection->each->sendWelcomeEmail();

    return $result;
}

// LazyCollection
/** @param LazyCollection<int, User> $lazy */
function testLazyCollectionProxyTypes(LazyCollection $lazy): void
{
    /** @psalm-check-type-exact $_proxy = HigherOrderCollectionProxy<'map', User, LazyCollection<int, User>> */
    $_proxy = $lazy->map;

    /** @psalm-check-type-exact $_filter = HigherOrderCollectionProxy<'filter', User, LazyCollection<int, User>> */
    $_filter = $lazy->filter;

    /** @psalm-check-type-exact $_reject = HigherOrderCollectionProxy<'reject', User, LazyCollection<int, User>> */
    $_reject = $lazy->reject;
}

/** @param LazyCollection<int, User> $lazy */
function testLazyMethodCallViaProxy(LazyCollection $lazy): LazyCollection
{
    /** @psalm-check-type-exact $result = LazyCollection<int, User> */
    $result = $lazy->each->sendWelcomeEmail();

    return $result;
}

// EloquentCollection — TModel must replace TValue in the proxy generic
/** @param EloquentCollection<int, User> $eloquent */
function testEloquentCollectionIntKeyedProxy(EloquentCollection $eloquent): void
{
    /** @psalm-check-type-exact $_proxy = HigherOrderCollectionProxy<'map', User, EloquentCollection<int, User>> */
    $_proxy = $eloquent->map;

    /** @psalm-check-type-exact $_eachProxy = HigherOrderCollectionProxy<'each', User, EloquentCollection<int, User>> */
    $_eachProxy = $eloquent->each;

    /** @psalm-check-type-exact $_filter = HigherOrderCollectionProxy<'filter', User, EloquentCollection<int, User>> */
    $_filter = $eloquent->filter;

    /** @psalm-check-type-exact $_reject = HigherOrderCollectionProxy<'reject', User, EloquentCollection<int, User>> */
    $_reject = $eloquent->reject;
}

// EloquentCollection — string keys (TKey propagation through @extends remapping)
/** @param EloquentCollection<string, User> $eloquent */
function testEloquentCollectionStringKeyedProxy(EloquentCollection $eloquent): void
{
    /** @psalm-check-type-exact $_proxy = HigherOrderCollectionProxy<'map', User, EloquentCollection<string, User>> */
    $_proxy = $eloquent->map;
}

/** @param EloquentCollection<int, User> $eloquent */
function testEloquentMethodCallViaProxy(EloquentCollection $eloquent): EloquentCollection
{
    /** @psalm-check-type-exact $result = EloquentCollection<int, User> */
    $result = $eloquent->each->sendWelcomeEmail();

    return $result;
}
// Laravel 13.31+ declares the proxy as HigherOrderCollectionProxy<TMethod, TValue, TCollection>, so
// user code can write the 3-argument shape. The stub's proxy accepts it for `$users->map`, and also
// accepts a wider Enumerable<int, User> collection argument (TCollection is covariant).
/** @param HigherOrderCollectionProxy<'map', User, Collection<int, User>> $_proxy */
function takesLaravelShapedMapProxy(HigherOrderCollectionProxy $_proxy): void {}

/** @param HigherOrderCollectionProxy<string, User, Enumerable<int, User>> $_proxy */
function takesWideShapedProxy(HigherOrderCollectionProxy $_proxy): void {}

/** @param HigherOrderCollectionProxy<'each', User, LazyCollection<int, User>> $_proxy */
function takesLazyShapedProxy(HigherOrderCollectionProxy $_proxy): void {}

/** @param HigherOrderCollectionProxy<'filter', User, EloquentCollection<int, User>> $_proxy */
function takesEloquentShapedProxy(HigherOrderCollectionProxy $_proxy): void {}

/**
 * @param Collection<int, User> $collection
 * @param LazyCollection<int, User> $lazy
 * @param EloquentCollection<int, User> $eloquent
 */
function testLaravelShapedProxyParams(Collection $collection, LazyCollection $lazy, EloquentCollection $eloquent): void
{
    takesLaravelShapedMapProxy($collection->map);
    takesWideShapedProxy($collection->map);
    takesWideShapedProxy($eloquent->map);
    takesLazyShapedProxy($lazy->each);
    takesEloquentShapedProxy($eloquent->filter);
}
?>
--EXPECTF--
