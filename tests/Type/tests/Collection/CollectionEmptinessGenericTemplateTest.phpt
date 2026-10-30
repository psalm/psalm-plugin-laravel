--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;

final class GenericEmptinessBadge
{
    public int $type = 1;
}

/**
 * The `isEmpty()` if-false assertion is `(TValue is null ? mixed : TValue)` (see the stub comment), so a
 * generic `T of X|null` must stay possibly-null after the guard instead of losing null.
 */
final class CollectionEmptinessGenericTemplateTest
{
    /**
     * @template T of GenericEmptinessBadge|null
     * @param Collection<int, T> $items
     */
    public function nullableBoundStaysNullable(Collection $items): int
    {
        if ($items->isEmpty()) {
            return 0;
        }

        return $items->first()->type;
    }

    /**
     * @template T of GenericEmptinessBadge
     * @param Collection<int, T> $items
     */
    public function nonNullBoundIsNarrowed(Collection $items): int
    {
        if ($items->isEmpty()) {
            return 0;
        }

        return $items->first()->type;
    }
}
?>
--EXPECTF--
InvalidNullableReturnType on line %d: The declared return type 'int' for CollectionEmptinessGenericTemplateTest::nullableBoundStaysNullable is not nullable, but 'int|null' contains null
NullableReturnStatement on line %d: The declared return type 'int' for CollectionEmptinessGenericTemplateTest::nullableBoundStaysNullable is not nullable, but the function returns 'int|null'
PossiblyNullPropertyFetch on line %d: Cannot get property on possibly null variable $items->first() of type T|null
