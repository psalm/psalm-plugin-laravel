--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\HigherOrderCollectionProxy;
use Illuminate\Support\LazyCollection;

/**
 * Small return-type fixes on `combine()`, `times()`, `fromJson()` and `unwrap()`, plus the
 * `$sole` higher-order proxy property.
 */
final class CollectionFactoryAndCombineTypesTest
{
    /**
     * Combined keys come from this collection's values, which can be any type; only array-key
     * values are valid keys at runtime.
     *
     * @param Collection<int, float> $floats
     * @param Collection<int, int|string> $keys
     */
    public function combineUsesArrayKey(Collection $floats, Collection $keys): void
    {
        $_byFloat = $floats->combine(['a', 'b']);
        /** @psalm-check-type-exact $_byFloat = Collection<array-key, 'a'|'b'>&static */

        $_byKey = $keys->combine([true, false]);
        /** @psalm-check-type-exact $_byKey = Collection<array-key, bool>&static */
    }

    /**
     * `times()` without a callback holds `range(1, $n)`, never an empty `never` collection.
     */
    public function timesWithoutCallback(): void
    {
        $_plain = Collection::times(3);
        /** @psalm-check-type-exact $_plain = Collection<int, int> */

        $_mapped = Collection::times(3, static fn (int $i): bool => $i > 1);
        /** @psalm-check-type-exact $_mapped = Collection<int, bool> */

        $_lazy = LazyCollection::times(3);
        /** @psalm-check-type-exact $_lazy = LazyCollection<int, int> */
    }

    /** `times()` without a callback is iterable with an int element. */
    public function timesElementIsInt(): int
    {
        $total = 0;
        Collection::times(3)->each(static function (int $i) use (&$total): void {
            $total += $i;
        });

        return $total;
    }

    /** `fromJson()` must not leak the class templates from a static call. */
    public function fromJsonHasNoLeakedTemplates(): void
    {
        $_decoded = Collection::fromJson('[1, 2]');
        /** @psalm-check-type-exact $_decoded = Collection<array-key, mixed> */
    }

    /**
     * `unwrap()` accepts any concrete collection and returns its array.
     *
     * @param Collection<string, int> $items
     */
    public function unwrapAcceptsConcreteCollections(Collection $items): void
    {
        $_fromCollection = Collection::unwrap($items);
        /** @psalm-check-type-exact $_fromCollection = array<string, int> */

        $_fromLiteral = Collection::unwrap(new Collection([1]));
        /** @psalm-check-type-exact $_fromLiteral = array<int, int> */

        $_fromArray = Collection::unwrap(['a' => true]);
        /** @psalm-check-type-exact $_fromArray = array<'a', true> */
    }

    /**
     * @param Collection<int, int> $items
     * @param LazyCollection<int, int> $lazy
     */
    public function soleProxyProperty(Collection $items, LazyCollection $lazy): void
    {
        $_proxy = $items->sole;
        /** @psalm-check-type-exact $_proxy = HigherOrderCollectionProxy<int, int> */

        $_lazyProxy = $lazy->sole;
        /** @psalm-check-type-exact $_lazyProxy = HigherOrderCollectionProxy<int, int> */
    }
}
?>
--EXPECTF--
