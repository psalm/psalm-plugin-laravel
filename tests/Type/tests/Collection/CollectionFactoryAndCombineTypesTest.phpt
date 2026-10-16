--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\HigherOrderCollectionProxy;
use Illuminate\Support\LazyCollection;

final class SoleFlag
{
    public function enabled(): bool
    {
        return true;
    }
}

/**
 * Small return-type fixes on `combine()` and `unwrap()`, plus the `$sole` higher-order proxy.
 * `times()` is covered for contextual typing of untyped callbacks.
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
     * `times()` keeps Laravel's reflected signature: an untyped callback gets its `int`
     * parameter from `callable(int): TTimesValue`. A conditional return that mentions
     * `$callback` loses that contextual typing (the closure then reports
     * MissingClosureParamType and the result degrades to `Collection<int, mixed>`).
     */
    public function timesUntypedCallback(): void
    {
        $_untyped = Collection::times(3, static fn ($i) => $i * 2);
        /** @psalm-check-type-exact $_untyped = Collection<int, int> */

        $_typed = Collection::times(3, static fn (int $i): bool => $i > 1);
        /** @psalm-check-type-exact $_typed = Collection<int, bool> */

        $_lazy = LazyCollection::times(3, static fn ($i) => $i * 2);
        /** @psalm-check-type-exact $_lazy = LazyCollection<int, int> */
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
     * `$sole` proxies a method call to `sole()`, which returns the selected item itself.
     *
     * @param Collection<int, SoleFlag> $items
     * @param LazyCollection<int, SoleFlag> $lazy
     */
    public function soleProxy(Collection $items, LazyCollection $lazy): void
    {
        $_proxy = $items->sole;
        /** @psalm-check-type-exact $_proxy = HigherOrderCollectionProxy<int, SoleFlag> */

        $_item = $items->sole->enabled();
        /** @psalm-check-type-exact $_item = SoleFlag */

        $_lazyItem = $lazy->sole->enabled();
        /** @psalm-check-type-exact $_lazyItem = SoleFlag */
    }
}
?>
--EXPECTF--
