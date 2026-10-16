--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\LazyCollection;

/** @extends Collection<string, int> */
final class PadCustomCollection extends Collection {}

/**
 * pad() delegates to array_pad(): string keys are kept and int keys are renumbered,
 * so the key type widens to `TKey|int` instead of Laravel's docblock `int`.
 */
final class CollectionPadTest
{
    /** @param Collection<string, int> $items */
    public function stringKeysAreKept(Collection $items): void
    {
        $_padded = $items->pad(5, 0);
        /** @psalm-check-type-exact $_padded = Collection<int|string, int>&static */

        $_mixed = $items->pad(5, 'x');
        /** @psalm-check-type-exact $_mixed = Collection<int|string, 'x'|int>&static */
    }

    /** @param Collection<int, string> $list */
    public function listStaysIntKeyed(Collection $list): void
    {
        $_padded = $list->pad(5, null);
        /** @psalm-check-type-exact $_padded = Collection<int, null|string>&static */
    }

    /** @param LazyCollection<string, int> $items */
    public function lazyCollectionKeepsStringKeys(LazyCollection $items): void
    {
        $_padded = $items->pad(5, 0);
        /** @psalm-check-type-exact $_padded = LazyCollection<int|string, int>&static */
    }

    /** @param Enumerable<string, int> $items */
    public function enumerableInterface(Enumerable $items): void
    {
        $_padded = $items->pad(5, 0);
        /** @psalm-check-type-exact $_padded = Enumerable<int|string, int>&static */
    }

    public function customCollectionSubclass(PadCustomCollection $items): void
    {
        $_padded = $items->pad(5, 0);
        /** @psalm-check-type-exact $_padded = PadCustomCollection */
    }
}
?>
--EXPECTF--
