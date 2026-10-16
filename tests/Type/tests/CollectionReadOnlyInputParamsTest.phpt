--FILE--
<?php declare(strict_types=1);

use App\Models\Admin;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
        // Untyped closure params must infer the union of both sides.
        $_diffUsing = $dogs->diffUsing($cats, function ($a, $b): int {
            /** @psalm-check-type-exact $a = ReadOnlyInputCat|ReadOnlyInputDog */
            /** @psalm-check-type-exact $b = ReadOnlyInputCat|ReadOnlyInputDog */
            return $a <=> $b;
        });
        /** @psalm-check-type-exact $_diffUsing = Collection<int, ReadOnlyInputDog>&static */

        $_intersectUsing = $dogs->intersectUsing($cats, function ($a, $b): int {
            /** @psalm-check-type-exact $a = ReadOnlyInputCat|ReadOnlyInputDog */
            /** @psalm-check-type-exact $b = ReadOnlyInputCat|ReadOnlyInputDog */
            return $a <=> $b;
        });
        /** @psalm-check-type-exact $_intersectUsing = Collection<int, ReadOnlyInputDog>&static */

        $_typed = $dogs->diffUsing($cats, fn (ReadOnlyInputDog|ReadOnlyInputCat $a, ReadOnlyInputDog|ReadOnlyInputCat $b): int => $a <=> $b);
        /** @psalm-check-type-exact $_typed = Collection<int, ReadOnlyInputDog>&static */

        // Key callbacks receive keys of both sides (array_*_uassoc / array_diff_ukey).
        $_diffAssocUsing = $dogs->diffAssocUsing(['x' => 1], function ($a, $b): int {
            /** @psalm-check-type-exact $a = int|'x' */
            /** @psalm-check-type-exact $b = int|'x' */
            return $a <=> $b;
        });
        /** @psalm-check-type-exact $_diffAssocUsing = Collection<int, ReadOnlyInputDog>&static */

        $_diffKeysUsing = $dogs->diffKeysUsing(['x' => 1], function ($a, $b): int {
            /** @psalm-check-type-exact $a = int|'x' */
            /** @psalm-check-type-exact $b = int|'x' */
            return $a <=> $b;
        });
        /** @psalm-check-type-exact $_diffKeysUsing = Collection<int, ReadOnlyInputDog>&static */

        $_intersectAssocUsing = $dogs->intersectAssocUsing(['x' => 1], function ($a, $b): int {
            /** @psalm-check-type-exact $a = int|'x' */
            /** @psalm-check-type-exact $b = int|'x' */
            return $a <=> $b;
        });
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

    /**
     * Eloquent collection methods call getKey() on every item, so values must stay models.
     *
     * @param EloquentCollection<int, Customer> $customers
     * @param EloquentCollection<int, Admin> $admins
     */
    public function eloquentKeepsModels(EloquentCollection $customers, EloquentCollection $admins): void
    {
        $_union = $customers->union($admins);
        /** @psalm-check-type-exact $_union = EloquentCollection<int, Admin|Customer>&static */

        $_replace = $customers->replace(['a' => new Admin()]);
        /** @psalm-check-type-exact $_replace = EloquentCollection<'a'|int, Admin|Customer>&static */

        $_replaceRecursive = $customers->replaceRecursive($admins);
        /** @psalm-check-type-exact $_replaceRecursive = EloquentCollection<int, Admin|Customer>&static */

        $_mergeRecursive = $customers->mergeRecursive($admins);
        /** @psalm-check-type-exact $_mergeRecursive = EloquentCollection<int, Admin|Customer>&static */

        // Non-model values would make modelKeys() call getKey() on an int.
        $customers->union([100 => 1]);
        $customers->replace([100 => 1]);
        $customers->replaceRecursive([100 => 1]);
        $customers->mergeRecursive([100 => 1]);
    }
}
--EXPECTF--
InvalidArgument on line %d: Type ReadOnlyInputCat should be a subtype of ReadOnlyInputDog
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Collection::union expects %s, but array{100: 1} provided
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Collection::replace expects %s, but array{100: 1} provided
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Collection::replaceRecursive expects %s, but array{100: 1} provided
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Collection::mergeRecursive expects %s, but array{100: 1} provided
