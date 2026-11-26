--FILE--
<?php declare(strict_types=1);

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1766
 *
 * collect([$a, $b]) bound the key range verbatim (`Collection<int<0, 1>, Item>`), and the invariant
 * TKey then rejected correct code such as `@return Collection<int, Item>`. Range keys become `int`;
 * values and literal keys stay as the stub binds them (see decisions.md, "Collection range keys").
 */

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

final class RangeKeyItem {}

final class RangeKeyHolder
{
    /** @var Collection<int, RangeKeyItem> */
    public Collection $items;

    /** @param list<RangeKeyItem> $list */
    public function __construct(array $list)
    {
        $this->items = collect($list);
    }
}

/** @return Collection<int, RangeKeyItem> */
function rangeKeyReturnsCollect(RangeKeyItem $a, RangeKeyItem $b): Collection
{
    return collect([$a, $b]);
}

/** @return Collection<int, RangeKeyItem> */
function rangeKeyReturnsMake(RangeKeyItem $a, RangeKeyItem $b): Collection
{
    return Collection::make([$a, $b]);
}

/** @return LazyCollection<int, RangeKeyItem> */
function rangeKeyReturnsLazyMake(RangeKeyItem $a, RangeKeyItem $b): LazyCollection
{
    return LazyCollection::make([$a, $b]);
}

/** @param list<RangeKeyItem> $list */
function rangeKeyFromList(array $list): void
{
    $_collect = collect($list);
    /** @psalm-check-type-exact $_collect = Collection<int, RangeKeyItem> */
}

function rangeKeyKeepsValues(RangeKeyItem $item): void
{
    // Only the range key widens; values keep the literals the stub binds.
    $_ints = collect([1, 2]);
    /** @psalm-check-type-exact $_ints = Collection<int, 1|2> */

    $_make = Collection::make([1, 2]);
    /** @psalm-check-type-exact $_make = Collection<int, 1|2> */

    $_lazy = LazyCollection::make([1, 2]);
    /** @psalm-check-type-exact $_lazy = LazyCollection<int, 1|2> */

    $_rows = collect([['id' => 1], ['id' => 2]]);
    /** @psalm-check-type-exact $_rows = Collection<int, array{id: 1|2}> */

    // Declines: a literal key is already lenient at invariant params, `[]` keeps its empty meaning.
    $_single = collect([$item]);
    /** @psalm-check-type-exact $_single = Collection<0, RangeKeyItem> */

    $_keyed = collect(['a' => 1, 'b' => 2]);
    /** @psalm-check-type-exact $_keyed = Collection<'a'|'b', 1|2> */

    $_empty = collect([]);
    /** @psalm-check-type-exact $_empty = Collection<never, never> */
}

// Literal keys stay, so bare `Collection` and string-keyed params keep accepting the result.
function rangeKeyBareParam(Collection $collection): int
{
    return $collection->count();
}

/** @param Collection<string, int> $collection */
function rangeKeyStringParam(Collection $collection): int
{
    return $collection->count();
}

function rangeKeyParamCalls(RangeKeyItem $item): void
{
    rangeKeyBareParam(collect([$item]));
    rangeKeyBareParam(collect(['a' => 1, 'b' => 2]));
    rangeKeyStringParam(collect(['a' => 1, 'b' => 2]));
}

function rangeKeyUnpacked(): void
{
    $args = [[1, 2]];

    // The unpacked array's FIRST element is the input, not the array itself: declined.
    $_collect = collect(...$args);
    /** @psalm-check-type-exact $_collect = Collection<int<0, 1>, 1|2> */

    $_make = Collection::make(...$args);
    /** @psalm-check-type-exact $_make = Collection<int<0, 1>, 1|2> */
}
?>
--EXPECTF--
