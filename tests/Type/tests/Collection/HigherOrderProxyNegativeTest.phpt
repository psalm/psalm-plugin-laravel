--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

class AnyModel extends Model {}

// Properties not in the $proxies list fall through to __get() which returns mixed.
// If stubs accidentally added @property-read for all properties, these assertions would fail.
/** @param Collection<int, stdClass> $collection */
function testUndefinedProxyOnCollection(Collection $collection): void
{
    /** @psalm-check-type-exact $_nonexistent = mixed */
    $_nonexistent = $collection->nonexistent;
}

/** @param LazyCollection<int, stdClass> $lazy */
function testUndefinedProxyOnLazyCollection(LazyCollection $lazy): void
{
    /** @psalm-check-type-exact $_nonexistent = mixed */
    $_nonexistent = $lazy->nonexistent;
}

/** @param EloquentCollection<int, AnyModel> $eloquent */
function testUndefinedProxyOnEloquentCollection(EloquentCollection $eloquent): void
{
    /** @psalm-check-type-exact $_nonexistent = mixed */
    $_nonexistent = $eloquent->nonexistent;
}
?>
--EXPECTF--
