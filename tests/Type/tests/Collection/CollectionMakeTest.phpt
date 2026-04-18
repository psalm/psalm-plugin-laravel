--FILE--
<?php declare(strict_types=1);

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * `Collection::make` / `LazyCollection::make` / `collect()` accept scalars, UnitEnums, and null
 * at runtime via `getArrayableItems()` → `Arr::wrap()`, and the widened stubs narrow those
 * inputs to `Collection<int, TScalar>` (matching the runtime `[$value]` wrapping).
 *
 * Template inference for `Arrayable<K,V>` / `iterable<K,V>` is preserved because the conditional
 * return type checks for those interfaces FIRST; see the Collection stub for the ordering note.
 */
enum TestMakeColor: string
{
    case Red = 'red';
}

final class CollectionMakeTest
{
    public function scalarStringYieldsSingleElementCollection(): void
    {
        $_c = Collection::make('some');
        /** @psalm-check-type-exact $_c = Collection<int, 'some'> */
    }

    public function scalarIntYieldsSingleElementCollection(): void
    {
        $_c = Collection::make(42);
        /** @psalm-check-type-exact $_c = Collection<int, 42> */
    }

    public function scalarFloatYieldsSingleElementCollection(): void
    {
        $_c = Collection::make(42.2);
        /** @psalm-check-type-exact $_c = Collection<int, 42.2> */
    }

    public function scalarBoolYieldsSingleElementCollection(): void
    {
        $_c = Collection::make(true);
        /** @psalm-check-type-exact $_c = Collection<int, true> */
    }

    public function unitEnumYieldsSingleElementCollection(): void
    {
        $_c = Collection::make(TestMakeColor::Red);
        /** @psalm-check-type-exact $_c = Collection<int, TestMakeColor::Red> */
    }

    public function nullYieldsEmptyCollection(): void
    {
        $_c = Collection::make(null);
        /** @psalm-check-type-exact $_c = Collection<never, never> */
    }

    public function listArrayPreservesKeyAndValueTypes(): void
    {
        $_c = Collection::make([1, 2, 3]);
        /** @psalm-check-type-exact $_c = Collection<int<0, 2>, 1|2|3> */
    }

    public function associativeArrayPreservesKeyAndValueTypes(): void
    {
        $_c = Collection::make(['a' => 1, 'b' => 2]);
        /** @psalm-check-type-exact $_c = Collection<'a'|'b', 1|2> */
    }

    /**
     * Regression guard: a naive conditional would pollute this with a spurious
     * `Collection<int, scalar|UnitEnum>` branch. The Arrayable|iterable-first ordering prevents that.
     *
     * @param iterable<int, \stdClass> $items
     */
    public function typedIterablePreservesItsTemplates(iterable $items): void
    {
        $_c = Collection::make($items);
        /** @psalm-check-type-exact $_c = Collection<int, stdClass> */
    }

    /** @param Arrayable<int, string> $items */
    public function typedArrayablePreservesItsTemplates(Arrayable $items): void
    {
        $_c = Collection::make($items);
        /** @psalm-check-type-exact $_c = Collection<int, string> */
    }

    /** @param Collection<int, \stdClass> $items */
    public function typedCollectionPreservesItsTemplates(Collection $items): void
    {
        $_c = Collection::make($items);
        /** @psalm-check-type-exact $_c = Collection<int, stdClass> */
    }

    public function lazyCollectionMakeScalarYieldsSingleElement(): void
    {
        $_c = LazyCollection::make('some');
        /** @psalm-check-type-exact $_c = LazyCollection<int, 'some'> */
    }

    public function lazyCollectionMakeNullYieldsEmpty(): void
    {
        $_c = LazyCollection::make(null);
        /** @psalm-check-type-exact $_c = LazyCollection<never, never> */
    }

    public function lazyCollectionMakeListPreservesTypes(): void
    {
        $_c = LazyCollection::make([1, 2, 3]);
        /** @psalm-check-type-exact $_c = LazyCollection<int<0, 2>, 1|2|3> */
    }

    /**
     * The Closure-returning-Generator form is the central LazyCollection use case.
     * Regression guard so the stub widening never drops this.
     */
    public function lazyCollectionMakeAcceptsGeneratorClosure(): void
    {
        $_c = LazyCollection::make(static function () {
            yield 1;
            yield 2;
        });
        /** @psalm-check-type-exact $_c = LazyCollection<int, 1|2> */
    }

    /** @param LazyCollection<int, string> $items */
    public function lazyCollectionMakeSelfPreservesTemplates(LazyCollection $items): void
    {
        $_c = LazyCollection::make($items);
        /** @psalm-check-type-exact $_c = LazyCollection<int, string> */
    }

    public function collectHelperScalarYieldsSingleElement(): void
    {
        $_c = collect('some');
        /** @psalm-check-type-exact $_c = Collection<int, 'some'> */
    }

    public function collectHelperIntYieldsSingleElement(): void
    {
        $_c = collect(42);
        /** @psalm-check-type-exact $_c = Collection<int, 42> */
    }

    public function collectHelperFloatYieldsSingleElement(): void
    {
        $_c = collect(42.2);
        /** @psalm-check-type-exact $_c = Collection<int, 42.2> */
    }

    public function collectHelperNullYieldsEmpty(): void
    {
        $_c = collect(null);
        /** @psalm-check-type-exact $_c = Collection<never, never> */
    }

    public function collectHelperUnitEnumYieldsSingleElement(): void
    {
        $_c = collect(TestMakeColor::Red);
        /** @psalm-check-type-exact $_c = Collection<int, TestMakeColor::Red> */
    }

    public function collectHelperListPreservesTypes(): void
    {
        $_c = collect([1, 2, 3]);
        /** @psalm-check-type-exact $_c = Collection<int<0, 2>, 1|2|3> */
    }

    /** @param Arrayable<int, string> $items */
    public function collectHelperArrayablePreservesTemplates(Arrayable $items): void
    {
        $_c = collect($items);
        /** @psalm-check-type-exact $_c = Collection<int, string> */
    }
}
?>
--EXPECT--
