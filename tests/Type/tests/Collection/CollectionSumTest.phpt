--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;

final class SumItem
{
    public int $quantity = 1;

    public float $price = 1.5;

    /** @var numeric-string */
    public string $weight = '1.5';
}

/**
 * `@psalm-check-type-exact` cannot tell `float` from `float|int` (int is float-compatible), so the
 * float-sensitive results are pinned with `@psalm-trace`.
 *
 * sum() starts its accumulator at int 0 and adds with `+`, so an empty collection yields int 0
 * whatever the item type: only an int-returning source narrows to int.
 */
final class CollectionSumTest
{
    /** @param Collection<int, int> $ints */
    public function noCallbackInts(Collection $ints): void
    {
        $_sum = $ints->sum();
        /** @psalm-check-type-exact $_sum = int */
    }

    /** @param Collection<int, float> $floats */
    public function noCallbackFloatsMayBeEmptyZero(Collection $floats): void
    {
        $_sum = $floats->sum();
        /** @psalm-trace $_sum */
    }

    /** @param Collection<int, SumItem> $items */
    public function callbackReturnTypes(Collection $items): void
    {
        $_int = $items->sum(fn (SumItem $item): int => $item->quantity);
        /** @psalm-check-type-exact $_int = int */

        $_float = $items->sum(fn (SumItem $item): float => $item->price);
        /** @psalm-trace $_float */

        $_string = $items->sum('price');
        /** @psalm-trace $_string */
    }

    /**
     * `0 + '1.5'` is 1.5 at runtime, so a numeric-string callback result is a valid addend.
     *
     * @param Collection<int, SumItem> $items
     */
    public function numericStringCallback(Collection $items): void
    {
        $_sum = $items->sum(fn (SumItem $item): string => $item->weight);
        /** @psalm-trace $_sum */
    }

    /** @param Collection<int, SumItem> $items */
    public function untypedClosureStillBindsItem(Collection $items): void
    {
        $_sum = $items->sum(fn ($item) => $item->quantity);
        /** @psalm-check-type-exact $_sum = int */
    }

    /** @param Collection<int, SumItem> $items */
    public function untypedClosureParamIsInferred(Collection $items): void
    {
        $items->sum(function ($item) {
            /** @psalm-check-type-exact $item = SumItem */
            return $item->quantity;
        });
    }
}
?>
--EXPECTF--
Trace on line %d: $_sum: float|int
Trace on line %d: $_float: float|int
Trace on line %d: $_string: float|int
Trace on line %d: $_sum: float|int
