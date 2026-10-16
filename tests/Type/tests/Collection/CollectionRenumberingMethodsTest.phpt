--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * `pop($n)`, `shift($n)` and `shuffle()` build a list at runtime, so the result is keyed by
 * int whatever the source keys were; `concat()` appends with `push()`, so source keys are
 * discarded and appended items take int keys.
 */
final class CollectionRenumberingMethodsTest
{
    /** @param Collection<string, int> $items */
    public function popAndShift(Collection $items): void
    {
        $_one = $items->pop();
        /** @psalm-check-type-exact $_one = int|null */

        $_oneExplicit = $items->shift(1);
        /** @psalm-check-type-exact $_oneExplicit = int|null */

        $_manyPop = $items->pop(3);
        /** @psalm-check-type-exact $_manyPop = Collection<int, int>&static */

        $_manyShift = $items->shift(2);
        /** @psalm-check-type-exact $_manyShift = Collection<int, int>&static */
    }

    /** @param Collection<string, int> $items */
    public function shuffleRenumbers(Collection $items): void
    {
        $_shuffled = $items->shuffle();
        /** @psalm-check-type-exact $_shuffled = Collection<int, int>&static */
    }

    /**
     * @param Collection<string, int> $items
     * @param list<string> $strings
     * @param array<string, float> $floats
     */
    public function concatKeepsKeyOrInt(Collection $items, array $strings, array $floats): void
    {
        $_concat = $items->concat($strings);
        /** @psalm-check-type-exact $_concat = Collection<int|string, int|string>&static */

        $_concatKeyed = $items->concat($floats);
        /** @psalm-check-type-exact $_concatKeyed = Collection<int|string, float|int>&static */
    }

    /** @param EloquentCollection<int, Customer> $customers */
    public function eloquentCollection(EloquentCollection $customers): void
    {
        $_one = $customers->shift();
        /** @psalm-check-type-exact $_one = Customer|null */

        $_many = $customers->pop(2);
        /** @psalm-check-type-exact $_many = EloquentCollection<int, Customer>&static */

        $_shuffled = $customers->shuffle();
        /** @psalm-check-type-exact $_shuffled = EloquentCollection<int, Customer>&static */
    }
}
?>
--EXPECTF--
