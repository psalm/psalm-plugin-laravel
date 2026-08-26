--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Collection::select() / LazyCollection::select() (issue #1388).
 *
 * Laravel source (Collection.php:1006, LazyCollection.php:985), not either method's PHPDoc,
 * is ground truth: a null $keys short-circuits to the untouched collection, any other $keys
 * maps every item through Arr::select() (Arr::map() under the hood), which loses the item's
 * original shape — the per-item result depends on the literal key list, so the non-null
 * branch widens to `array<array-key, mixed>` rather than trying to track individual keys.
 *
 * Eloquent\Collection::select() is NOT inherited as-is: the non-null branch's items shrink to
 * `array<array-key, mixed>`, which violates `TModel of Model`, so it must widen to
 * `Illuminate\Support\Collection<TKey, array<array-key, mixed>>` — a widening, not a lie, since
 * the runtime object genuinely IS-A Support\Collection.
 */
final class CollectionSelectTest
{
    /** @param Collection<int, array{a: int, b: int, c: int}> $collection */
    public function collectionSelectNonNull(Collection $collection): void
    {
        $_result = $collection->select(['a', 'b']);
        /** @psalm-check-type-exact $_result = Collection<int, array<array-key, mixed>>&static */
    }

    /** @param Collection<int, array{a: int, b: int, c: int}> $collection */
    public function collectionSelectNull(Collection $collection): void
    {
        $_result = $collection->select(null);
        /** @psalm-check-type-exact $_result = Collection<int, array{a: int, b: int, c: int}>&static */
    }

    /** @param LazyCollection<int, array{a: int, b: int, c: int}> $collection */
    public function lazyCollectionSelectBothBranches(LazyCollection $collection): void
    {
        $_selected = $collection->select(['a', 'b']);
        /** @psalm-check-type-exact $_selected = LazyCollection<int, array<array-key, mixed>>&static */

        $_untouched = $collection->select(null);
        /** @psalm-check-type-exact $_untouched = LazyCollection<int, array{a: int, b: int, c: int}>&static */
    }

    /** @param Collection<int, array{a: int, b: int, c: int}> $collection */
    public function collectionSelectVariadic(Collection $collection): void
    {
        $_result = $collection->select('a', 'b');
        /** @psalm-check-type-exact $_result = Collection<int, array<array-key, mixed>>&static */
    }

    /** @param EloquentCollection<int, Customer> $customers */
    public function eloquentCollectionSelectNonNull(EloquentCollection $customers): void
    {
        $_result = $customers->select(['id']);
        /** @psalm-check-type-exact $_result = Illuminate\Support\Collection<int, array<array-key, mixed>> */
    }

    /** @param EloquentCollection<int, Customer> $customers */
    public function eloquentCollectionSelectNull(EloquentCollection $customers): void
    {
        $_result = $customers->select(null);
        /** @psalm-check-type-exact $_result = EloquentCollection<int, Customer>&static */
    }

    /**
     * Regression teeth: without the Eloquent override, the non-null branch inherits Support\Collection's
     * `static<TKey, array<array-key, mixed>>`, which fails `TModel of Model` and turns every subsequent
     * chained call (filter/values here) into InvalidTemplateParam. Red without the override.
     *
     * @param EloquentCollection<int, Customer> $customers
     */
    public function eloquentCollectionSelectChained(EloquentCollection $customers): void
    {
        $customers->select(['id'])->filter()->values();
    }
}
?>
--EXPECTF--
