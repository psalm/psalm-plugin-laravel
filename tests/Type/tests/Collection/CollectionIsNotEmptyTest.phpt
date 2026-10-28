--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;

final class IsNotEmptyBadge
{
    public int $type = 1;
}

/**
 * `isNotEmpty()` carries no assertion on `first()`: negating an
 * `@psalm-assert-if-false null $this->first()` in the true branch stored the raw declared
 * return type of `first()` (unresolved class templates and the closure conditional), so
 * `$c->first()->type` reported a non-object property fetch instead of a nullable fetch.
 */
final class CollectionIsNotEmptyTest
{
    /** @param Collection<int, IsNotEmptyBadge> $badges */
    public function firstAfterIsNotEmpty(Collection $badges): int
    {
        if ($badges->isNotEmpty()) {
            $first = $badges->first();
            /** @psalm-check-type-exact $first = IsNotEmptyBadge|null */

            return $first?->type ?? 0;
        }

        return 0;
    }

    /** @param Collection<int, IsNotEmptyBadge> $badges */
    public function firstOrNullAfterIsNotEmpty(Collection $badges): ?IsNotEmptyBadge
    {
        return $badges->isNotEmpty() ? $badges->first() : null;
    }

    /** @param Collection<int, IsNotEmptyBadge> $badges */
    public function isEmptyKeepsNullNarrowing(Collection $badges): null
    {
        return $badges->isEmpty() ? $badges->first() : null;
    }
}
?>
--EXPECTF--
