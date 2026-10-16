--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1814
 *
 * EmptyCollectionLookupIssueHandler drops only the issue quoting the never-substituted parameter.
 * Wrong lookups on populated collections, including the populated atomic of a union that also holds
 * an empty collection, and on user classes that redeclare the lookup, keep reporting.
 */
final class KeptLookupItem
{
    public string $name = '';
}

/**
 * @template TKey of array-key
 * @template TValue
 * @extends Collection<TKey, TValue>
 */
final class KeptLookupBag extends Collection
{
    /**
     * @psalm-mutation-free
     *
     * @param TKey $key
     * @param mixed $default
     * @return mixed
     */
    #[\Override]
    public function get($key, $default = null): mixed
    {
        return parent::get($key, $default);
    }
}

/** @param Collection<int, KeptLookupItem> $items */
function populated_wrong_key(Collection $items): bool
{
    return $items->get('name') !== null
        && $items->has('name')
        && $items->contains(1);
}

/** @param Collection<int, KeptLookupItem>|Collection<never, never> $either */
function populated_atomic_of_union(Collection $either): bool
{
    // Exactly one issue per call: the int-keyed atomic's. The never atomic's is dropped.
    return $either->get('name') !== null && $either->has('name');
}

/** @param EloquentCollection<int, Customer> $customers */
function eloquent_wrong_key(EloquentCollection $customers): bool
{
    return $customers->has('name') && $customers->get('name') !== null;
}

/** @param KeptLookupBag<never, never> $bag */
function user_override_keeps_issue(KeptLookupBag $bag): mixed
{
    return $bag->get('name');
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::get expects int|null, but 'name' provided
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::has expects array<array-key, int>|int, but 'name' provided
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::contains expects KeptLookupItem|callable[impure](KeptLookupItem, int):bool|string, but 1 provided
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::get expects int|null, but 'name' provided
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::has expects array<array-key, int>|int, but 'name' provided
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Collection::has expects array<array-key, int>|int, but 'name' provided
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Collection::get expects int|null, but 'name' provided
InvalidArgument on line %d: Argument 1 of KeptLookupBag::get expects never, but 'name' provided
