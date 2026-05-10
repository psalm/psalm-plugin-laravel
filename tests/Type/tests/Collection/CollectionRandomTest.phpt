--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Collection::random() stub alignment with Laravel's actual signature.
 *
 * Covers:
 *  - `random()` with no argument returns a single TValue (works correctly today).
 *  - `random(callable)` accepts a closure receiving the whole collection
 *    (callable(self<TKey, TValue>): int), matching Laravel's
 *    `Illuminate\Collections\Collection::random` where `$number($this)` is called.
 *    Before this stub change the param was `callable(TValue): int`, which produced
 *    a spurious `InvalidArgument` on callers like
 *    `$collection->random(fn (Collection $items): int => ...)`.
 *  - `random(int, bool)` compiles without TooManyArguments — the `$preserveKeys`
 *    second parameter was missing from the stub.
 *
 * NOT covered here: the conditional return type `($number is null ? TValue :
 * static<int, TValue>)` does not currently narrow correctly when `$number` is
 * non-null. That is tracked separately in
 * https://github.com/psalm/psalm-plugin-laravel/issues/903 and is out of scope
 * for this stub-signature fix.
 */
final class CollectionRandomTest
{
    /** @return EloquentCollection<int, Customer> */
    public function getCustomers(): EloquentCollection
    {
        return Customer::all();
    }

    /**
     * No argument: returns a single Customer.
     *
     * @psalm-check-type-exact $result = Customer
     */
    public function randomNoArg(): Customer
    {
        $result = $this->getCustomers()->random();

        return $result;
    }

    /**
     * Closure argument typed against the parent Support\Collection: no InvalidArgument.
     * This reproduces the monicahq/monica pattern in VaultIndexViewHelper.
     */
    public function randomCallable(): void
    {
        $this->getCustomers()->random(
            fn (BaseCollection $items): int => min(5, $items->count()),
        );
    }

    /**
     * preserveKeys positional argument compiles without TooManyArguments.
     */
    public function randomWithPreserveKeys(): void
    {
        $this->getCustomers()->random(3, true);
    }
}
?>
--EXPECTF--
