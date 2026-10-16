--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1814
 *
 * Psalm checks lookup arguments per receiver atomic with the class templates substituted, so an
 * empty `Collection<never, never>` expects `never` and rejects every key. Looking something up in
 * an empty collection is valid, so EmptyCollectionLookupIssueHandler drops those issues. The
 * populated side of the same shapes is pinned in EmptyCollectionLookupKeepsRealIssuesTest.phpt.
 */
final class LookupItem
{
    public string $name = '';
}

function consume(mixed ...$values): bool
{
    return $values !== [];
}

function bare_empty_receivers(string $key): bool
{
    $empty = collect();

    return consume(
        collect()->get($key),
        collect()->get($key, 'default'),
        collect()->has($key),
        collect()->hasAny($key),
        collect()->contains(1),
        collect()->containsStrict(1),
        collect()->doesntContain(1),
        collect()->search(1),
        Collection::empty()->has($key),
        isset($empty['k']),
        isset($empty[$key]),
    );
}

function empty_eloquent_collection(string $key): bool
{
    $customers = new EloquentCollection();

    return $customers->has($key)
        && $customers->hasAny($key)
        && $customers->get($key) === null
        && $customers->contains($key);
}

/** @param Collection<string, LookupItem> $items */
function merged_with_empty(Collection $items, bool $flag, string $key): bool
{
    $ternary = $flag ? $items : collect();

    if ($flag) {
        $merged = $items;
    } else {
        $merged = collect();
    }

    return $ternary->has($key)
        && $ternary->get($key) !== null
        && $merged->has($key)
        && $merged->get($key) !== null
        && $merged->contains(new LookupItem());
}

/** @param EloquentCollection<int, Customer> $customers */
function issue_1814_eloquent_keyed_ternary(EloquentCollection $customers, bool $flag, string $id): bool
{
    $byId = $flag
        ? $customers->keyBy(static fn (Customer $c): string => $c->id)
        : collect();

    return $byId->has($id) && $byId->get($id) !== null;
}

function declared_laravel_signatures(string $default): bool
{
    $items = new Collection(['a' => 1, 'b' => 2]);

    // Laravel declares `TKey|null` for get() and reads func_get_args() in except/only/hasAny.
    return $items->get(null) === null
        && $items->except('a', 'b')->isEmpty()
        && $items->only('a', 'b')->isEmpty()
        && $items->hasAny('a', 'b')
        && collect()->except('a', 'b')->isEmpty()
        && collect()->only('a', 'b')->isEmpty()
        && collect()->hasAny('a', 'b')
        && collect()->get(null, $default) === $default;
}
?>
--EXPECTF--
