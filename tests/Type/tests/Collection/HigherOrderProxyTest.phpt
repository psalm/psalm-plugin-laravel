--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
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
    /** @psalm-check-type-exact $_mapProxy = HigherOrderCollectionProxy<int, User> */
    $_mapProxy = $collection->map;

    /** @psalm-check-type-exact $_filterProxy = HigherOrderCollectionProxy<int, User> */
    $_filterProxy = $collection->filter;

    /** @psalm-check-type-exact $_rejectProxy = HigherOrderCollectionProxy<int, User> */
    $_rejectProxy = $collection->reject;
}

// Collection — string keys (TKey must propagate through the stub)
/** @param Collection<string, User> $collection */
function testCollectionStringKeyedProxy(Collection $collection): void
{
    /** @psalm-check-type-exact $_proxy = HigherOrderCollectionProxy<string, User> */
    $_proxy = $collection->map;
}

// Method calls on the proxy return Enumerable<TKey, TValue>, reflecting the actual
// Laravel runtime behaviour: each() returns the collection, not the callback's return value.
// (Previously typed as bool via @mixin TValue, which was incorrect.)
/** @param Collection<int, User> $collection */
function testMethodCallViaProxy(Collection $collection): \Illuminate\Support\Enumerable
{
    /** @psalm-check-type-exact $result = \Illuminate\Support\Enumerable<int, User> */
    $result = $collection->each->sendWelcomeEmail();

    return $result;
}

// LazyCollection
/** @param LazyCollection<int, User> $lazy */
function testLazyCollectionProxyTypes(LazyCollection $lazy): void
{
    /** @psalm-check-type-exact $_proxy = HigherOrderCollectionProxy<int, User> */
    $_proxy = $lazy->map;

    /** @psalm-check-type-exact $_filter = HigherOrderCollectionProxy<int, User> */
    $_filter = $lazy->filter;

    /** @psalm-check-type-exact $_reject = HigherOrderCollectionProxy<int, User> */
    $_reject = $lazy->reject;
}

/** @param LazyCollection<int, User> $lazy */
function testLazyMethodCallViaProxy(LazyCollection $lazy): \Illuminate\Support\Enumerable
{
    /** @psalm-check-type-exact $result = \Illuminate\Support\Enumerable<int, User> */
    $result = $lazy->each->sendWelcomeEmail();

    return $result;
}

// EloquentCollection — TModel must replace TValue in the proxy generic
/** @param EloquentCollection<int, User> $eloquent */
function testEloquentCollectionIntKeyedProxy(EloquentCollection $eloquent): void
{
    /** @psalm-check-type-exact $_proxy = HigherOrderCollectionProxy<int, User> */
    $_proxy = $eloquent->map;

    /** @psalm-check-type-exact $_eachProxy = HigherOrderCollectionProxy<int, User> */
    $_eachProxy = $eloquent->each;

    /** @psalm-check-type-exact $_filter = HigherOrderCollectionProxy<int, User> */
    $_filter = $eloquent->filter;

    /** @psalm-check-type-exact $_reject = HigherOrderCollectionProxy<int, User> */
    $_reject = $eloquent->reject;
}

// EloquentCollection — string keys (TKey propagation through @extends remapping)
/** @param EloquentCollection<string, User> $eloquent */
function testEloquentCollectionStringKeyedProxy(EloquentCollection $eloquent): void
{
    /** @psalm-check-type-exact $_proxy = HigherOrderCollectionProxy<string, User> */
    $_proxy = $eloquent->map;
}

/** @param EloquentCollection<int, User> $eloquent */
function testEloquentMethodCallViaProxy(EloquentCollection $eloquent): \Illuminate\Support\Enumerable
{
    /** @psalm-check-type-exact $result = \Illuminate\Support\Enumerable<int, User> */
    $result = $eloquent->each->sendWelcomeEmail();

    return $result;
}
?>
--EXPECTF--
