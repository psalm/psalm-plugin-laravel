--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * filter() without callback removes null/false and narrows string/array.
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/441
 */
final class CollectionFilterTest
{
    /** @param Collection<int, string|null> $collection */
    public function filterRemovesNull(Collection $collection): void
    {
        $_filtered = $collection->filter();
        /** @psalm-check-type-exact $_filtered = Collection<int, non-falsy-string>&static */
    }

    /** @param Collection<int, string|false> $collection */
    public function filterRemovesFalse(Collection $collection): void
    {
        $_filtered = $collection->filter();
        /** @psalm-check-type-exact $_filtered = Collection<int, non-falsy-string>&static */
    }

    /** @param Collection<int, string|null|false> $collection */
    public function filterRemovesNullAndFalse(Collection $collection): void
    {
        $_filtered = $collection->filter();
        /** @psalm-check-type-exact $_filtered = Collection<int, non-falsy-string>&static */
    }

    /**
     * filter() with a callback should NOT narrow — we don't know what the callback filters.
     * @param Collection<int, string|null> $collection
     */
    public function filterWithCallbackDoesNotNarrow(Collection $collection): void
    {
        $_result = $collection->filter(fn (string|null $item) => $item !== null);
        /** @psalm-check-type-exact $_result = Collection<int, null|string>&static */
    }

    /**
     * The original issue's use case: map() producing nullable, then filter().
     * @param Collection<int, string> $collection
     */
    public function mapThenFilter(Collection $collection): void
    {
        $_filtered = $collection
            ->map(fn (string $item): ?string => strlen($item) > 3 ? $item : null)
            ->filter();
        /** @psalm-check-type-exact $_filtered = Collection<int, non-falsy-string>&static */
    }

    /** @param LazyCollection<int, string|null> $collection */
    public function lazyCollectionFilterRemovesNull(LazyCollection $collection): void
    {
        $_filtered = $collection->filter();
        /** @psalm-check-type-exact $_filtered = LazyCollection<int, non-falsy-string>&static */
    }

    /**
     * string narrows to non-falsy-string (array_filter removes "" and "0").
     * @param Collection<int, string> $collection
     */
    public function filterNarrowsStringToNonEmpty(Collection $collection): void
    {
        $_filtered = $collection->filter();
        /** @psalm-check-type-exact $_filtered = Collection<int, non-falsy-string>&static */
    }

    /**
     * array narrows to non-empty-array (array_filter removes []).
     * @param Collection<int, array<string, int>> $collection
     */
    public function filterNarrowsArrayToNonEmpty(Collection $collection): void
    {
        $_filtered = $collection->filter();
        /** @psalm-check-type-exact $_filtered = Collection<int, non-empty-array<string, int>>&static */
    }

    /**
     * non-empty-string stays non-empty-string (already a TString subclass, not narrowed further).
     * @param Collection<int, non-empty-string|null> $collection
     */
    public function filterPreservesNonEmptyString(Collection $collection): void
    {
        $_filtered = $collection->filter();
        /** @psalm-check-type-exact $_filtered = Collection<int, non-empty-string>&static */
    }

    /**
     * int is NOT narrowed (no non-zero-int atomic type in Psalm).
     * @param Collection<int, int|null> $collection
     */
    public function filterDoesNotNarrowInt(Collection $collection): void
    {
        $_filtered = $collection->filter();
        /** @psalm-check-type-exact $_filtered = Collection<int, int>&static */
    }

    /**
     * Eloquent Collection extends Support Collection, so the handler fires for it too.
     * TModel is constrained to Model, so null isn't valid here — but the handler still
     * works for narrowing other falsy types or leaving the type unchanged.
     * @param \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model> $collection
     */
    public function eloquentCollectionFilterPassesThrough(\Illuminate\Database\Eloquent\Collection $collection): void
    {
        $_filtered = $collection->filter();
        /** @psalm-check-type-exact $_filtered = \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model>&static */
    }

    /**
     * filter(null) behaves identically to filter() in Laravel.
     * @param Collection<int, string|null> $collection
     */
    public function filterWithExplicitNullNarrows(Collection $collection): void
    {
        $_filtered = $collection->filter(null);
        /** @psalm-check-type-exact $_filtered = Collection<int, non-falsy-string>&static */
    }

    /**
     * float is NOT narrowed (same reasoning as int — no non-zero-float type).
     * @param Collection<int, float|null> $collection
     */
    public function filterDoesNotNarrowFloat(Collection $collection): void
    {
        $_filtered = $collection->filter();
        /** @psalm-check-type-exact $_filtered = Collection<int, float>&static */
    }

    /**
     * When TValue has nothing to narrow, handler defers to Psalm's default.
     * @param Collection<int, int> $collection
     */
    public function filterWithNothingToNarrow(Collection $collection): void
    {
        $_filtered = $collection->filter();
        /** @psalm-check-type-exact $_filtered = Collection<int, int>&static */
    }
}
?>
--EXPECT--
