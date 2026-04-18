--FILE--
<?php declare(strict_types=1);

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * `Collection::make` / `LazyCollection::make` / `collect()` accept scalars, UnitEnums, and null
 * at runtime via `getArrayableItems()` → `Arr::wrap()`. The stubs widen the parameter so those
 * calls type-check instead of raising InvalidArgument.
 *
 * Scalar inputs intentionally do NOT narrow to `Collection<int, TScalar>`: that pattern pollutes
 * the return type for the common Arrayable/iterable path (see the Collection stub for details).
 * The `check-type-exact` assertions below are the regression-guard line for the supported shapes.
 */
enum TestMakeColor: string
{
    case Red = 'red';
}

final class CollectionMakeTest
{
    /**
     * Scalar inputs deliberately collapse to the generic form rather than narrowing to
     * `Collection<int, 'some'>`: the narrower signature polluted the common
     * Arrayable/iterable path (see the stub docblock). The exact-type assertion below
     * is the guard so nobody re-introduces the narrowing without understanding the tradeoff.
     */
    public function scalarInputsResolveToGenericCollection(): void
    {
        $_s = Collection::make('some');
        /** @psalm-check-type-exact $_s = Collection<array-key, mixed> */

        $_i = Collection::make(42);
        /** @psalm-check-type-exact $_i = Collection<array-key, mixed> */

        $_b = Collection::make(true);
        /** @psalm-check-type-exact $_b = Collection<array-key, mixed> */

        $_f = Collection::make(1.5);
        /** @psalm-check-type-exact $_f = Collection<array-key, mixed> */
    }

    public function unitEnumResolvesToGenericCollection(): void
    {
        $_e = Collection::make(TestMakeColor::Red);
        /** @psalm-check-type-exact $_e = Collection<array-key, mixed> */
    }

    public function nullResolvesToGenericCollection(): void
    {
        $_n = Collection::make(null);
        /** @psalm-check-type-exact $_n = Collection<array-key, mixed> */
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
     * Regression guard: previously the widened stub polluted this case with a spurious
     * scalar-branch union like `Collection<int, scalar|\UnitEnum> | Collection<int, stdClass>`.
     *
     * @param iterable<int, \stdClass> $items
     */
    public function typedIterablePreservesItsTemplates(iterable $items): void
    {
        $_c = Collection::make($items);
        /** @psalm-check-type-exact $_c = Collection<int, stdClass> */
    }

    /**
     * Same regression guard for the `Arrayable` branch: the scalar widening must not
     * leak into the inferred value type when the input is a typed Arrayable.
     *
     * @param Arrayable<int, string> $items
     */
    public function typedArrayablePreservesItsTemplates(Arrayable $items): void
    {
        $_c = Collection::make($items);
        /** @psalm-check-type-exact $_c = Collection<int, string> */
    }

    /**
     * Passing an existing Collection: it implements Arrayable, so the Arrayable branch
     * applies and the input templates are preserved.
     *
     * @param Collection<int, \stdClass> $items
     */
    public function typedCollectionPreservesItsTemplates(Collection $items): void
    {
        $_c = Collection::make($items);
        /** @psalm-check-type-exact $_c = Collection<int, stdClass> */
    }

    public function lazyCollectionScalarResolvesToGenericCollection(): void
    {
        $_s = LazyCollection::make('some');
        /** @psalm-check-type-exact $_s = LazyCollection<array-key, mixed> */

        $_n = LazyCollection::make(null);
        /** @psalm-check-type-exact $_n = LazyCollection<array-key, mixed> */
    }

    /**
     * The `self<TMakeKey, TMakeValue>` alternative in the LazyCollection::make param union
     * (distinct from the Arrayable/iterable branches used by `Collection::make`).
     *
     * @param LazyCollection<int, string> $items
     */
    public function lazyCollectionMakeSelfPreservesTemplates(LazyCollection $items): void
    {
        $_c = LazyCollection::make($items);
        /** @psalm-check-type-exact $_c = LazyCollection<int, string> */
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

    public function collectHelperScalarTypeChecks(): void
    {
        collect('some');
        collect(42);
        collect(null);
        collect(TestMakeColor::Red);
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
