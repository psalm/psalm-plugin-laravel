--FILE--
<?php declare(strict_types=1);

use App\Models\CollectionGroupingModel as Item;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Support\Collection methods inherited by Eloquent\Collection return `static<K, V>` with a V that can stop
 * being a model; that nests a non-model into `TModel of Model` (InvalidTemplateParam, #1699). The stub
 * widens the OUTER collection to Support\Collection and names the inner Eloquent class explicitly.
 *
 * @param EloquentCollection<int, Item> $items
 */
function nested_collections_are_clean(EloquentCollection $items): void
{
    $_chunk = $items->chunk(2);
    /** @psalm-check-type-exact $_chunk = Collection<int, EloquentCollection<int, Item>> */

    $_chunkNoKeys = $items->chunk(2, false);
    /** @psalm-check-type-exact $_chunkNoKeys = Collection<int, EloquentCollection<int, Item>> */

    $_split = $items->split(2);
    /** @psalm-check-type-exact $_split = Collection<int, EloquentCollection<int, Item>> */

    $_sliding = $items->sliding(2);
    /** @psalm-check-type-exact $_sliding = Collection<int, EloquentCollection<int, Item>> */

    $_partition = $items->partition(static fn (Item $i): bool => $i->active);
    /** @psalm-check-type-exact $_partition = Collection<int<0, 1>, EloquentCollection<int, Item>> */

    // Inner groups must stay named: a `static` inside the Support outer is re-bound to it on later calls.
    $_first = $_chunk->first();
    /** @psalm-check-type-exact $_first = EloquentCollection<int, Item>|null */
}

/** @param EloquentCollection<int, Item> $items */
function value_changing_methods_are_clean(EloquentCollection $items): void
{
    $_groups = $items->mapToGroups(static fn (Item $i): array => [$i->foreign_id => $i->active]);
    /** @psalm-check-type-exact $_groups = Collection<int, Collection<int, bool>> */

    $_dictionary = $items->mapToDictionary(static fn (Item $i): array => [$i->foreign_id => $i->active]);
    /** @psalm-check-type-exact $_dictionary = Collection<int, array<int, bool>> */

    $_into = $items->mapInto(\ArrayObject::class);
    /** @psalm-check-type-exact $_into = Collection<int, ArrayObject<array-key, mixed>> */

    $_spread = $items->mapSpread(static fn (mixed ...$xs): int => \count($xs));
    /** @psalm-check-type-exact $_spread = Collection<int, int<0, max>> */

    $_combined = $items->combine([1, 2]);
    /** @psalm-check-type-exact $_combined = Collection<array-key, 1|2> */

    $_flat = $items->flatMap(static fn (Item $i): array => [$i->foreign_id]);
    /** @psalm-check-type-exact $_flat = Collection<0, int> */

    $_crossed = $items->crossJoin([1, 2]);
    /** @psalm-check-type-exact $_crossed = Collection<int, array<int, 1|2|Item>> */

    $_concat = $items->concat([1, 2]);
    /** @psalm-check-type-exact $_concat = Collection<int, 1|2|Item> */

    $_collapsed = $items->collapseWithKeys();
    /** @psalm-check-type-exact $_collapsed = Collection<array-key, mixed> */

    $_times = EloquentCollection::times(3, static fn (int $n): int => $n);
    /** @psalm-check-type-exact $_times = Collection<int, int> */
}
?>
--EXPECTF--
