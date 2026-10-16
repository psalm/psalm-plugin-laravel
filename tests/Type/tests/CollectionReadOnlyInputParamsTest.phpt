--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\LazyCollection;

final class ReadOnlyInputDog {}

final class ReadOnlyInputCat {}

/**
 * Comparison methods only read their input, and union/replace/mergeRecursive widen the result,
 * so a foreign value or key type must be accepted instead of being bound to TKey/TValue.
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1892
 */
final class CollectionReadOnlyInputParamsTest
{
    /**
     * @param Collection<int, ReadOnlyInputDog> $dogs
     * @param Collection<int, ReadOnlyInputCat> $cats
     */
    public function valueAndKeyComparison(Collection $dogs, Collection $cats, ReadOnlyInputCat $cat): void
    {
        $_ints = collect([3, 4])->diff([1, 2]);
        /** @psalm-check-type-exact $_ints = Collection<int<0, 1>, 3|4>&static */

        $_diff = $dogs->diff($cats);
        /** @psalm-check-type-exact $_diff = Collection<int, ReadOnlyInputDog>&static */

        $_diffAssoc = $dogs->diffAssoc($cats);
        /** @psalm-check-type-exact $_diffAssoc = Collection<int, ReadOnlyInputDog>&static */

        $_diffKeys = $dogs->diffKeys(['x' => 'y']);
        /** @psalm-check-type-exact $_diffKeys = Collection<int, ReadOnlyInputDog>&static */

        $_intersect = $dogs->intersect($cats);
        /** @psalm-check-type-exact $_intersect = Collection<int, ReadOnlyInputDog>&static */

        $_intersectAssoc = $dogs->intersectAssoc($cats);
        /** @psalm-check-type-exact $_intersectAssoc = Collection<int, ReadOnlyInputDog>&static */

        $_intersectByKeys = $dogs->intersectByKeys(['a' => 1]);
        /** @psalm-check-type-exact $_intersectByKeys = Collection<int, ReadOnlyInputDog>&static */
    }

    /**
     * @param Collection<int, ReadOnlyInputDog> $dogs
     * @param Collection<int, ReadOnlyInputCat> $cats
     */
    public function callbackComparison(Collection $dogs, Collection $cats): void
    {
        $_untyped = $dogs->diffUsing($cats, fn ($a, $b) => $a <=> $b);
        /** @psalm-check-type-exact $_untyped = Collection<int, ReadOnlyInputDog>&static */

        $_typed = $dogs->diffUsing($cats, fn (ReadOnlyInputDog|ReadOnlyInputCat $a, ReadOnlyInputDog|ReadOnlyInputCat $b): int => $a <=> $b);
        /** @psalm-check-type-exact $_typed = Collection<int, ReadOnlyInputDog>&static */

        $_intersectUsing = $dogs->intersectUsing($cats, fn ($a, $b) => $a <=> $b);
        /** @psalm-check-type-exact $_intersectUsing = Collection<int, ReadOnlyInputDog>&static */

        // Key callbacks receive keys of both sides (array_*_uassoc / array_diff_ukey).
        $_diffAssocUsing = $dogs->diffAssocUsing(['x' => 1], fn (int|string $a, int|string $b): int => $a <=> $b);
        /** @psalm-check-type-exact $_diffAssocUsing = Collection<int, ReadOnlyInputDog>&static */

        $_diffKeysUsing = $dogs->diffKeysUsing(['x' => 1], fn (int|string $a, int|string $b): int => $a <=> $b);
        /** @psalm-check-type-exact $_diffKeysUsing = Collection<int, ReadOnlyInputDog>&static */

        $_intersectAssocUsing = $dogs->intersectAssocUsing(['x' => 1], fn (int|string $a, int|string $b): int => $a <=> $b);
        /** @psalm-check-type-exact $_intersectAssocUsing = Collection<int, ReadOnlyInputDog>&static */

        // The callback also receives the foreign (Cat) side, so a Dog-only closure is unsound.
        $_dogOnly = $dogs->diffUsing($cats, fn (ReadOnlyInputDog $a, ReadOnlyInputDog $b): int => $a <=> $b);
    }

    /**
     * @param Collection<int, ReadOnlyInputDog> $dogs
     */
    public function widening(Collection $dogs, ReadOnlyInputCat $cat): void
    {
        $_union = $dogs->union([10 => $cat]);
        /** @psalm-check-type-exact $_union = Collection<int, ReadOnlyInputCat|ReadOnlyInputDog>&static */

        $_replace = $dogs->replace(['a' => $cat]);
        /** @psalm-check-type-exact $_replace = Collection<'a'|int, ReadOnlyInputCat|ReadOnlyInputDog>&static */

        $_replaceRecursive = $dogs->replaceRecursive(['a' => $cat]);
        /** @psalm-check-type-exact $_replaceRecursive = Collection<'a'|int, ReadOnlyInputCat|ReadOnlyInputDog>&static */

        $_mergeRecursive = $dogs->mergeRecursive(['a' => $cat]);
        /** @psalm-check-type-exact $_mergeRecursive = Collection<'a'|int, ReadOnlyInputCat|ReadOnlyInputDog>&static */
    }

    /**
     * @param LazyCollection<int, ReadOnlyInputDog> $dogs
     * @param LazyCollection<int, ReadOnlyInputCat> $cats
     */
    public function lazyCollection(LazyCollection $dogs, LazyCollection $cats, ReadOnlyInputCat $cat): void
    {
        $_diff = $dogs->diff($cats);
        /** @psalm-check-type-exact $_diff = LazyCollection<int, ReadOnlyInputDog>&static */

        $_diffUsing = $dogs->diffUsing($cats, fn ($a, $b) => $a <=> $b);
        /** @psalm-check-type-exact $_diffUsing = LazyCollection<int, ReadOnlyInputDog>&static */

        $_union = $dogs->union([10 => $cat]);
        /** @psalm-check-type-exact $_union = LazyCollection<int, ReadOnlyInputCat|ReadOnlyInputDog>&static */
    }

    /**
     * @param Enumerable<int, ReadOnlyInputDog> $dogs
     * @param Enumerable<int, ReadOnlyInputCat> $cats
     */
    public function enumerable(Enumerable $dogs, Enumerable $cats, ReadOnlyInputCat $cat): void
    {
        $_diff = $dogs->diff($cats);
        /** @psalm-check-type-exact $_diff = Enumerable<int, ReadOnlyInputDog>&static */

        $_diffUsing = $dogs->diffUsing($cats, fn ($a, $b) => $a <=> $b);
        /** @psalm-check-type-exact $_diffUsing = Enumerable<int, ReadOnlyInputDog>&static */

        $_union = $dogs->union([10 => $cat]);
        /** @psalm-check-type-exact $_union = Enumerable<int, ReadOnlyInputCat|ReadOnlyInputDog>&static */
    }
}
--EXPECTF--
InvalidArgument on line %d: Type ReadOnlyInputCat should be a subtype of ReadOnlyInputDog
