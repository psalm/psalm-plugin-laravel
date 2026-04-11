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
    /** @psalm-check-type-exact $mapProxy = HigherOrderCollectionProxy<int, User> */
    $mapProxy = $collection->map;
    unset($mapProxy);

    /** @psalm-check-type-exact $filterProxy = HigherOrderCollectionProxy<int, User> */
    $filterProxy = $collection->filter;
    unset($filterProxy);

    /** @psalm-check-type-exact $rejectProxy = HigherOrderCollectionProxy<int, User> */
    $rejectProxy = $collection->reject;
    unset($rejectProxy);
}

// Collection — string keys (TKey must propagate through the stub)
/** @param Collection<string, User> $collection */
function testCollectionStringKeyedProxy(Collection $collection): void
{
    /** @psalm-check-type-exact $proxy = HigherOrderCollectionProxy<string, User> */
    $proxy = $collection->map;
    unset($proxy);
}

// Method calls on the proxy are typed via @mixin TValue
/** @param Collection<int, User> $collection */
function testMethodCallViaProxy(Collection $collection): bool
{
    /**
     * Method calls on the proxy are typed via @mixin TValue.
     * @psalm-check-type-exact $result = bool
     */
    $result = $collection->each->sendWelcomeEmail();

    return $result;
}

// LazyCollection
/** @param LazyCollection<int, User> $lazy */
function testLazyCollectionProxyTypes(LazyCollection $lazy): void
{
    /** @psalm-check-type-exact $proxy = HigherOrderCollectionProxy<int, User> */
    $proxy = $lazy->map;
    unset($proxy);

    /** @psalm-check-type-exact $filter = HigherOrderCollectionProxy<int, User> */
    $filter = $lazy->filter;
    unset($filter);

    /** @psalm-check-type-exact $reject = HigherOrderCollectionProxy<int, User> */
    $reject = $lazy->reject;
    unset($reject);
}

/** @param LazyCollection<int, User> $lazy */
function testLazyMethodCallViaProxy(LazyCollection $lazy): bool
{
    /** @psalm-check-type-exact $result = bool */
    $result = $lazy->each->sendWelcomeEmail();

    return $result;
}

// EloquentCollection — TModel must replace TValue in the proxy generic
/** @param EloquentCollection<int, User> $eloquent */
function testEloquentCollectionProxyTypes(EloquentCollection $eloquent): void
{
    /** @psalm-check-type-exact $proxy = HigherOrderCollectionProxy<int, User> */
    $proxy = $eloquent->map;
    unset($proxy);

    /** @psalm-check-type-exact $eachProxy = HigherOrderCollectionProxy<int, User> */
    $eachProxy = $eloquent->each;
    unset($eachProxy);

    /** @psalm-check-type-exact $filter = HigherOrderCollectionProxy<int, User> */
    $filter = $eloquent->filter;
    unset($filter);

    /** @psalm-check-type-exact $reject = HigherOrderCollectionProxy<int, User> */
    $reject = $eloquent->reject;
    unset($reject);
}

/** @param EloquentCollection<int, User> $eloquent */
function testEloquentMethodCallViaProxy(EloquentCollection $eloquent): bool
{
    /** @psalm-check-type-exact $result = bool */
    $result = $eloquent->each->sendWelcomeEmail();

    return $result;
}
?>
--EXPECTF--
