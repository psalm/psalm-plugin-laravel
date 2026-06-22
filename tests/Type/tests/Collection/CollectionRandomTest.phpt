--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\LazyCollection;

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
 *
 * Also covers (#1149): the same no-arg narrowing on the `Enumerable` interface
 * and `LazyCollection` receivers, which previously fell back to Laravel's
 * reflected `static<int, TValue>|TValue` union. Receivers are bare `Enumerable`/
 * `LazyCollection` (via `@param`) so calls route to those stubs, not Collection.
 * Asserted only for the no-arg branch (the headline fix); the non-null branch is
 * the #903 limitation noted above. `LazyCollection::random` also carries the
 * `$preserveKeys` second parameter (Enumerable's does not) — guarded below.
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
     */
    public function randomNoArg(): Customer
    {
        $result = $this->getCustomers()->random();
        /** @psalm-check-type-exact $result = Customer */

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

    /**
     * #1149: bare Enumerable-interface receiver, no argument → single Customer.
     * Routes to the interface stub (not Collection); before #1149 this fell back
     * to the reflected static<int, TValue>|TValue union.
     *
     * @param  Enumerable<int, Customer>  $items
     */
    public function enumerableRandomNoArg(Enumerable $items): Customer
    {
        $result = $items->random();
        /** @psalm-check-type-exact $result = Customer */

        return $result;
    }

    /**
     * #1149: bare LazyCollection receiver, no argument → single Customer.
     *
     * @param  LazyCollection<int, Customer>  $items
     */
    public function lazyCollectionRandomNoArg(LazyCollection $items): Customer
    {
        $result = $items->random();
        /** @psalm-check-type-exact $result = Customer */

        return $result;
    }

    /**
     * #1149: LazyCollection::random carries $preserveKeys (Enumerable's does not);
     * the two-argument form compiles without TooManyArguments.
     *
     * @param  LazyCollection<int, Customer>  $items
     */
    public function lazyCollectionRandomWithPreserveKeys(LazyCollection $items): void
    {
        $items->random(3, true);
    }
}
?>
--EXPECTF--
