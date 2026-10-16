--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\LazyCollection;

/**
 * `LazyCollection::collapse()` yields values without their keys, so a lazy `flatMap()` always
 * produces int keys, whatever keys the callback's arrays carry. `Collection` keeps string keys
 * (`Arr::collapse()`), and an `Enumerable` receiver may be either, so it admits both.
 */
final class CollectionFlatMapLazyKeysTest
{
    /**
     * @param LazyCollection<int, string> $lazy
     * @param LazyCollection<int, list<string>> $lists
     */
    public function lazy(LazyCollection $lazy, LazyCollection $lists): void
    {
        $_stringKeyed = $lazy->flatMap(static fn ($v) => ['k' => $v]);
        /** @psalm-check-type-exact $_stringKeyed = LazyCollection<int, string>&static */

        $_list = $lists->flatMap(static fn (array $row): array => $row);
        /** @psalm-check-type-exact $_list = LazyCollection<int, string>&static */
    }

    /** @param Collection<int, string> $items */
    public function collectionKeepsStringKeys(Collection $items): void
    {
        $_stringKeyed = $items->flatMap(static fn ($v) => ['k' => $v]);
        /** @psalm-check-type-exact $_stringKeyed = Collection<'k', string>&static */
    }

    /**
     * @param Enumerable<int, string> $enumerable
     * @param Enumerable<int, list<string>> $lists
     */
    public function enumerable(Enumerable $enumerable, Enumerable $lists): void
    {
        $_stringKeyed = $enumerable->flatMap(static fn ($v) => ['k' => $v]);
        /** @psalm-check-type-exact $_stringKeyed = Enumerable<'k'|int, string>&static */

        $_list = $lists->flatMap(static fn (array $row): array => $row);
        /** @psalm-check-type-exact $_list = Enumerable<int, string>&static */
    }
}
?>
--EXPECTF--
