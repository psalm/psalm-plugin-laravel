--FILE--
<?php declare(strict_types=1);

/**
 * @see https://github.com/vimeo/psalm/issues/10985
 *
 * Psalm infers array literal and list keys as `int<0, n>` or literal strings, and TKey is
 * invariant, so collect()/make() results were rejected where `Collection<int, T>` or
 * `Collection<string, T>` is declared. CollectionInputTypeResolver widens those keys.
 */

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

final class Item {}

/** @param list<Item> $list */
function widens(array $list, Item $a, Item $b): void
{
    $_literal = collect([$a, $b]);
    /** @psalm-check-type-exact $_literal = Collection<int, Item> */

    $_list = collect($list);
    /** @psalm-check-type-exact $_list = Collection<int, Item> */

    $_stringKeys = collect(['x' => $a, 'y' => $b]);
    /** @psalm-check-type-exact $_stringKeys = Collection<string, Item> */

    $_mixedKeys = collect([$a, 'x' => $b]);
    /** @psalm-check-type-exact $_mixedKeys = Collection<int|string, Item> */

    $_make = Collection::make([$a, $b]);
    /** @psalm-check-type-exact $_make = Collection<int, Item>&static */

    $_lazy = LazyCollection::make([$a, $b]);
    /** @psalm-check-type-exact $_lazy = LazyCollection<int, Item>&static */

    $_filtered = collect([$a, null])->filter();
    /** @psalm-check-type-exact $_filtered = Collection<int, Item>&static */
}

/**
 * @param array<string, Item> $byName
 * @param array<array-key, Item> $anyKey
 * @param iterable<int<0, 5>, Item> $iterable
 */
function declines(array $byName, array $anyKey, iterable $iterable): void
{
    $_byName = collect($byName);
    /** @psalm-check-type-exact $_byName = Collection<string, Item> */

    $_anyKey = collect($anyKey);
    /** @psalm-check-type-exact $_anyKey = Collection<array-key, Item> */

    $_empty = collect([]);
    /** @psalm-check-type-exact $_empty = Collection<never, never> */

    $_iterable = collect($iterable);
    /** @psalm-check-type-exact $_iterable = Collection<int<0, 5>, Item> */
}

/** @return Collection<int, Item> */
function returnsDeclared(Item $a, Item $b): Collection
{
    return collect([$a, $b]);
}

/** @param Collection<string, Item> $items */
function takesDeclared(Collection $items): int
{
    return $items->count();
}

function passesDeclared(Item $a): int
{
    return takesDeclared(collect(['a' => $a]));
}
--EXPECTF--
