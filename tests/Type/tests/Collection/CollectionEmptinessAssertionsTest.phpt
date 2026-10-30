--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class IsNotEmptyBadge
{
    public int $type = 1;
}

final class IsNotEmptyOtherBadge
{
    public int $type = 2;
}

/**
 * `isEmpty()` narrows `first()` (null when empty, non-null `TValue` otherwise); `isNotEmpty()` narrows only `count()`.
 *
 * The stub keeps exactly one if-true and one if-false assertion on `isEmpty()`, both on `first()`:
 * vimeo/psalm#9994 drops the if-false assertions of a method with several method-call assertions,
 * and a missing if-true half lets Laravel's `@phpstan-assert-if-true null $this->last()` merge in.
 * The if-false type is `(TValue is null ? mixed : TValue)` and `isNotEmpty()` asserts nothing on `first()`/`last()`
 * because vimeo/psalm#11533: template-expanded assertions keep only the first union member, which would turn
 * `int|null` into `int` and `A|B` into `A`. A nullable `TValue` therefore stays nullable (sound, not narrowed).
 * `$first->type` on the narrowed value proves the class templates resolve (an unresolved template
 * once leaked from a negated assertion and surfaced as a non-object property fetch).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2166
 */
final class CollectionEmptinessAssertionsTest
{
    /** @param Collection<int, IsNotEmptyBadge> $badges */
    public function firstAfterIsEmptyEarlyReturn(Collection $badges): int
    {
        if ($badges->isEmpty()) {
            return 0;
        }

        $first = $badges->first();
        /** @psalm-check-type-exact $first = IsNotEmptyBadge */

        return $first->type;
    }

    /** @param Collection<int, IsNotEmptyBadge> $badges */
    public function firstInsideIsEmptyBranch(Collection $badges): void
    {
        if ($badges->isEmpty()) {
            $_first = $badges->first();
            /** @psalm-check-type-exact $_first = null */
        }
    }

    /** @param Collection<int, IsNotEmptyBadge|IsNotEmptyOtherBadge> $badges */
    public function unionValueAfterIsEmptyEarlyReturn(Collection $badges): void
    {
        if ($badges->isEmpty()) {
            return;
        }

        $_first = $badges->first();
        /** @psalm-check-type-exact $_first = IsNotEmptyBadge|IsNotEmptyOtherBadge */
    }

    /** @param Collection<int, IsNotEmptyBadge|IsNotEmptyOtherBadge|null> $badges */
    public function nullableUnionValueAfterIsEmptyEarlyReturn(Collection $badges): void
    {
        if ($badges->isEmpty()) {
            return;
        }

        $_first = $badges->first();
        /** @psalm-check-type-exact $_first = IsNotEmptyBadge|IsNotEmptyOtherBadge|null */
    }

    /** @param Collection<int, IsNotEmptyBadge> $badges */
    public function countAfterIsNotEmpty(Collection $badges): int
    {
        if ($badges->isNotEmpty()) {
            $count = $badges->count();
            /** @psalm-check-type-exact $count = int<1, max> */

            return $count;
        }

        return 0;
    }

    /** @param Collection<int, IsNotEmptyBadge> $badges */
    public function firstLastStayNullableAfterIsNotEmpty(Collection $badges): int
    {
        if ($badges->isNotEmpty()) {
            $first = $badges->first();
            /** @psalm-check-type-exact $first = IsNotEmptyBadge|null */

            $last = $badges->last();
            /** @psalm-check-type-exact $last = IsNotEmptyBadge|null */

            return $first?->type ?? $last?->type ?? 0;
        }

        return 0;
    }

    /** @param Collection<int, IsNotEmptyBadge|IsNotEmptyOtherBadge> $badges */
    public function unionValueKeepsFullUnionAfterIsNotEmpty(Collection $badges): void
    {
        if ($badges->isNotEmpty()) {
            $_first = $badges->first();
            /** @psalm-check-type-exact $_first = IsNotEmptyBadge|IsNotEmptyOtherBadge|null */
        }
    }

    /** @param Collection<int, IsNotEmptyBadge> $badges */
    public function firstOrNullAfterIsNotEmpty(Collection $badges): ?IsNotEmptyBadge
    {
        return $badges->isNotEmpty() ? $badges->first() : null;
    }

    /** @param Collection<int, IsNotEmptyBadge|IsNotEmptyOtherBadge> $badges */
    public function firstAfterNegatedIsNotEmptyEarlyReturn(Collection $badges): void
    {
        if (! $badges->isNotEmpty()) {
            return;
        }

        $_first = $badges->first();
        /** @psalm-check-type-exact $_first = IsNotEmptyBadge|IsNotEmptyOtherBadge|null */
    }

    /** @param EloquentCollection<int, Customer> $customers */
    public function eloquentCollectionReceiver(EloquentCollection $customers): void
    {
        if ($customers->isEmpty()) {
            return;
        }

        $_first = $customers->first();
        /** @psalm-check-type-exact $_first = Customer */
    }

    /** @param Collection<int, IsNotEmptyBadge|null> $badges */
    public function nullableValueStaysNullable(Collection $badges): void
    {
        if ($badges->isEmpty()) {
            return;
        }

        $_first = $badges->first();
        /** @psalm-check-type-exact $_first = IsNotEmptyBadge|null */
    }

    /** @param Collection<int, int|null> $numbers */
    public function nullableIntStaysNullable(Collection $numbers): void
    {
        if ($numbers->isEmpty()) {
            return;
        }

        $_first = $numbers->first();
        /** @psalm-check-type-exact $_first = int|null */
    }

    /** @param Collection<int, int|string|null> $values */
    public function nullableScalarUnionStaysNullable(Collection $values): void
    {
        if ($values->isEmpty()) {
            return;
        }

        $_first = $values->first();
        /** @psalm-check-type-exact $_first = int|string|null */
    }

    /** @param Collection<int, int|string> $values */
    public function nonNullScalarUnionIsKeptWhole(Collection $values): void
    {
        if ($values->isEmpty()) {
            return;
        }

        $_first = $values->first();
        /** @psalm-check-type-exact $_first = int|string */
    }

    /** @param Collection<int, IsNotEmptyBadge|null> $badges */
    public function nullableValueIsNullInsideIsEmptyBranch(Collection $badges): void
    {
        if ($badges->isEmpty()) {
            $_first = $badges->first();
            /** @psalm-check-type-exact $_first = null */
        }
    }

    /** @param Collection<int, class-string<Model>> $models */
    public function reportedShapeClassStringStaticCall(Collection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $model = $models->first();
        /** @psalm-check-type-exact $model = class-string<Model> */

        $model::query();
    }
}
?>
--EXPECTF--
