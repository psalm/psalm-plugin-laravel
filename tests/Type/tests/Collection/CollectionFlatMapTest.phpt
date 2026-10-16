--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\LazyCollection;

/**
 * `flatMap()` binds the result key from the callback's array. A list-shaped literal result
 * (`[$row['id']]`) bound it to `0` and a list to `int<0, max>`, both rejected by an invariant
 * `Collection<int, V>` target. Int keys widen to `int`; string keys survive.
 */
final class CollectionFlatMapTest
{
    /**
     * @param Collection<int, array{id: int, tags: list<string>, meta: array<string, bool>}> $rows
     * @param Collection<string, float> $inner
     */
    public function collection(Collection $rows, Collection $inner): void
    {
        $_literalList = $rows->flatMap(static fn (array $row): array => [$row['id']]);
        /** @psalm-check-type-exact $_literalList = Collection<int, int>&static */

        $_list = $rows->flatMap(static fn (array $row): array => $row['tags']);
        /** @psalm-check-type-exact $_list = Collection<int, string>&static */

        $_stringKeys = $rows->flatMap(static fn (array $row): array => $row['meta']);
        /** @psalm-check-type-exact $_stringKeys = Collection<string, bool>&static */

        $_fromCollection = $rows->flatMap(static fn (): Collection => $inner);
        /** @psalm-check-type-exact $_fromCollection = Collection<string, float>&static */
    }

    /**
     * @param Collection<int, list<string>> $rows
     * @return Collection<int, string>
     */
    public function resultAssignableToInvariantTarget(Collection $rows): Collection
    {
        return $rows->flatMap(static fn (array $row): array => $row);
    }

    /**
     * @param LazyCollection<int, list<string>> $rows
     * @param Enumerable<int, list<string>> $enumerable
     */
    public function otherReceivers(LazyCollection $rows, Enumerable $enumerable): void
    {
        $_lazy = $rows->flatMap(static fn (array $row): array => $row);
        /** @psalm-check-type-exact $_lazy = LazyCollection<int, string>&static */

        $_enumerable = $enumerable->flatMap(static fn (array $row): array => $row);
        /** @psalm-check-type-exact $_enumerable = Enumerable<int, string>&static */
    }
}
?>
--EXPECTF--
