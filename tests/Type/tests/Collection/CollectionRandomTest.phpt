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
 * Non-null `$number` (int or callable) returns `static<int, TValue>`: the conditional
 * return needs the unused method-level `@template` in the stubs (vimeo/psalm#11837),
 * otherwise it collapses to the `TValue` branch (#903).
 *
 * Also covers (#1149): the same conditional on the `Enumerable` interface
 * and `LazyCollection` receivers, which previously fell back to Laravel's
 * reflected `static<int, TValue>|TValue` union. Receivers are bare `Enumerable`/
 * `LazyCollection` (via `@param`) so calls route to those stubs, not Collection.
 * `LazyCollection::random` also carries the
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

    /**
     * Non-null `$number` returns a collection keyed by int, on every receiver.
     *
     * @param  BaseCollection<string, Customer>  $base
     * @param  LazyCollection<string, Customer>  $lazy
     * @param  Enumerable<string, Customer>  $enumerable
     */
    public function randomWithNumberReturnsCollection(BaseCollection $base, LazyCollection $lazy, Enumerable $enumerable): void
    {
        $_fromInt = $base->random(2);
        /** @psalm-check-type-exact $_fromInt = BaseCollection<int, Customer>&static */

        $_fromCallable = $base->random(static fn (BaseCollection $items): int => $items->count());
        /** @psalm-check-type-exact $_fromCallable = BaseCollection<int, Customer>&static */

        $_single = $base->random();
        /** @psalm-check-type-exact $_single = Customer */

        $_lazyMany = $lazy->random(2);
        /** @psalm-check-type-exact $_lazyMany = LazyCollection<int, Customer>&static */

        $_enumerableMany = $enumerable->random(2);
        /** @psalm-check-type-exact $_enumerableMany = Enumerable<int, Customer>&static */

        $_eloquentMany = $this->getCustomers()->random(2);
        /** @psalm-check-type-exact $_eloquentMany = EloquentCollection<int, Customer>&static */
    }

    /**
     * `$preserveKeys = true` keeps the source keys; a plain bool yields both shapes.
     *
     * @param  BaseCollection<string, Customer>  $base
     * @param  LazyCollection<string, Customer>  $lazy
     */
    public function randomPreserveKeys(BaseCollection $base, LazyCollection $lazy, bool $flag): void
    {
        $_preserved = $base->random(2, true);
        /** @psalm-check-type-exact $_preserved = BaseCollection<string, Customer>&static */

        $_notPreserved = $base->random(2, false);
        /** @psalm-check-type-exact $_notPreserved = BaseCollection<int, Customer>&static */

        $_unknown = $base->random(2, $flag);
        /** @psalm-check-type-exact $_unknown = BaseCollection<int, Customer>&static|BaseCollection<string, Customer>&static */

        $_lazyPreserved = $lazy->random(2, true);
        /** @psalm-check-type-exact $_lazyPreserved = LazyCollection<string, Customer>&static */

        $_lazyUnknown = $lazy->random(2, $flag);
        /** @psalm-check-type-exact $_lazyUnknown = LazyCollection<int, Customer>&static|LazyCollection<string, Customer>&static */
    }
}
?>
--EXPECTF--
