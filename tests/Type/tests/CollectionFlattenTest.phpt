--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * flatten(1) preserves inner collection/array value types.
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/617
 */
final class CollectionFlattenTest
{
    /** @param Collection<int, Collection<int, string>> $collection */
    public function flattenOneNestedCollection(Collection $collection): void
    {
        $_result = $collection->flatten(1);
        /** @psalm-check-type-exact $_result = Collection<int, string>&static */
    }

    /** @param Collection<int, array<string, int>> $collection */
    public function flattenOneNestedArray(Collection $collection): void
    {
        $_result = $collection->flatten(1);
        /** @psalm-check-type-exact $_result = Collection<int, int>&static */
    }

    /** @param Collection<string, list<string>> $collection */
    public function flattenOneNestedList(Collection $collection): void
    {
        $_result = $collection->flatten(1);
        /** @psalm-check-type-exact $_result = Collection<int, string>&static */
    }

    /**
     * flatten(0) is equivalent to flatten(INF) in Laravel — defers to default.
     * @param Collection<string, int> $collection
     */
    public function flattenZeroDefersToDefault(Collection $collection): void
    {
        $_result = $collection->flatten(0);
        /** @psalm-check-type-exact $_result = Collection<int, mixed>&static */
    }

    /**
     * flatten() with no argument (INF depth) falls through to default (mixed).
     * @param Collection<int, Collection<int, string>> $collection
     */
    public function flattenInfDepthReturnsMixed(Collection $collection): void
    {
        $_result = $collection->flatten();
        /** @psalm-check-type-exact $_result = Collection<int, mixed>&static */
    }

    /** @param LazyCollection<int, Collection<int, string>> $collection */
    public function lazyCollectionFlattenOne(LazyCollection $collection): void
    {
        $_result = $collection->flatten(1);
        /** @psalm-check-type-exact $_result = LazyCollection<int, string>&static */
    }

    /**
     * flatten(1) on a non-nested collection defers to Psalm's default (mixed).
     * @param Collection<int, string> $collection
     */
    public function flattenOneOnFlatCollection(Collection $collection): void
    {
        $_result = $collection->flatten(1);
        /** @psalm-check-type-exact $_result = Collection<int, mixed>&static */
    }

    /**
     * flatten(2) is not handled — defers to Psalm's default.
     * @param Collection<int, Collection<int, Collection<int, string>>> $collection
     */
    public function flattenTwoDefersToDefault(Collection $collection): void
    {
        $_result = $collection->flatten(2);
        /** @psalm-check-type-exact $_result = Collection<int, mixed>&static */
    }

    /**
     * collapse() is semantically equivalent to flatten(1).
     * @param Collection<int, Collection<int, string>> $collection
     */
    public function collapsePreservesInnerType(Collection $collection): void
    {
        $_result = $collection->collapse();
        /** @psalm-check-type-exact $_result = Collection<int, string>&static */
    }

    /** @param Collection<int, array<string, int>> $collection */
    public function collapseNestedArray(Collection $collection): void
    {
        $_result = $collection->collapse();
        /** @psalm-check-type-exact $_result = Collection<int, int>&static */
    }

    /**
     * The issue's original use case: map-then-flatten(1).
     * @param Collection<int, array{category: string, items: list<string>}> $groups
     */
    public function mapThenFlatten(Collection $groups): void
    {
        $_result = $groups
            ->map(function (array $group): Collection {
                /** @var Collection<int, array{name: string, category: string}> */
                return collect($group['items'])->map(
                    fn(string $item): array => ['name' => $item, 'category' => $group['category']]
                );
            })
            ->flatten(1);
        /** @psalm-check-type-exact $_result = Collection<int, array{name: string, category: string}>&static */
    }
}
?>
--EXPECT--
