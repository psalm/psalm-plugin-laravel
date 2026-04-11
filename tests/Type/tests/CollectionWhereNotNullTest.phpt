--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * whereNotNull() without a key removes null from TValue.
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/706
 */
final class CollectionWhereNotNullTest
{
    /** @param Collection<int, string|null> $collection */
    public function whereNotNullRemovesNull(Collection $collection): void
    {
        $_filtered = $collection->whereNotNull();
        /** @psalm-check-type-exact $_filtered = Collection<int, string>&static */
    }

    /** @param Collection<int, int|null> $collection */
    public function whereNotNullRemovesNullFromInt(Collection $collection): void
    {
        $_filtered = $collection->whereNotNull();
        /** @psalm-check-type-exact $_filtered = Collection<int, int>&static */
    }

    /**
     * whereNotNull() does NOT remove false — it only guards against null.
     * @param Collection<int, string|false> $collection
     */
    public function whereNotNullDoesNotRemoveFalse(Collection $collection): void
    {
        $_filtered = $collection->whereNotNull();
        /** @psalm-check-type-exact $_filtered = Collection<int, false|string>&static */
    }

    /**
     * whereNotNull() removes null but does NOT narrow string to non-falsy-string.
     * This distinguishes whereNotNull() from filter(), which would narrow string → non-falsy-string.
     * @param Collection<int, string|int|null> $collection
     */
    public function whereNotNullOnlyRemovesNull(Collection $collection): void
    {
        $_filtered = $collection->whereNotNull();
        /** @psalm-check-type-exact $_filtered = Collection<int, int|string>&static */
    }

    /**
     * whereNotNull(null) is equivalent to whereNotNull() — explicit null key means filter by item value.
     * @param Collection<int, string|null> $collection
     */
    public function whereNotNullWithExplicitNullKey(Collection $collection): void
    {
        $_filtered = $collection->whereNotNull(null);
        /** @psalm-check-type-exact $_filtered = Collection<int, string>&static */
    }

    /**
     * whereNotNull('key') filters by a nested field — TValue is unchanged (can't narrow).
     * @param Collection<int, array{name: string|null}> $collection
     */
    public function whereNotNullWithStringKeyDoesNotNarrow(Collection $collection): void
    {
        $_filtered = $collection->whereNotNull('name');
        /** @psalm-check-type-exact $_filtered = Collection<int, array{name: null|string}>&static */
    }

    /** @param LazyCollection<int, string|null> $collection */
    public function lazyCollectionWhereNotNullRemovesNull(LazyCollection $collection): void
    {
        $_filtered = $collection->whereNotNull();
        /** @psalm-check-type-exact $_filtered = LazyCollection<int, string>&static */
    }

    /**
     * When TValue has no null, handler defers to Psalm's default.
     * @param Collection<int, string> $collection
     */
    public function whereNotNullWithNothingToNarrow(Collection $collection): void
    {
        $_filtered = $collection->whereNotNull();
        /** @psalm-check-type-exact $_filtered = Collection<int, string>&static */
    }

    /**
     * Eloquent Collection extends Support Collection, so the handler fires for it too.
     * @param \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model> $collection
     */
    public function eloquentCollectionWhereNotNullPassesThrough(
        \Illuminate\Database\Eloquent\Collection $collection,
    ): void {
        $_filtered = $collection->whereNotNull();
        /** @psalm-check-type-exact $_filtered = \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model>&static */
    }

    /**
     * The primary use case from the issue: map() produces nullable, then whereNotNull() cleans it.
     * @param Collection<int, string> $collection
     */
    public function mapThenWhereNotNull(Collection $collection): void
    {
        $_filtered = $collection
            ->map(fn (string $item): ?string => strlen($item) > 3 ? $item : null)
            ->whereNotNull();
        /** @psalm-check-type-exact $_filtered = Collection<int, string>&static */
    }

    /**
     * non-empty-string is preserved (not widened) — whereNotNull only strips null.
     * @param Collection<int, non-empty-string|null> $collection
     */
    public function whereNotNullPreservesNonEmptyString(Collection $collection): void
    {
        $_filtered = $collection->whereNotNull();
        /** @psalm-check-type-exact $_filtered = Collection<int, non-empty-string>&static */
    }

    /**
     * The original issue's use case.
     * @param Collection<int, string|null> $items
     */
    public function originalIssueUseCase(Collection $items): void
    {
        $_filtered = $items->whereNotNull();
        /** @psalm-check-type-exact $_filtered = Collection<int, string>&static */
    }
}
?>
--EXPECT--
