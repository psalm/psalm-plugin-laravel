--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\LazyCollection;

/**
 * A closure default only runs on a miss, so `first()` and `get()` return the closure's result
 * joined with `TValue` (same as `last()` and `pull()`). Laravel's single `TFirstDefault|(\Closure(): TFirstDefault)`
 * template is swept on the lazy and interface receivers too.
 */
final class CollectionFirstGetClosureDefaultTest
{
    /** @param Collection<int, string> $items */
    public function collection(Collection $items): void
    {
        $_firstScalar = $items->first(null, 1);
        /** @psalm-check-type-exact $_firstScalar = 1|string */

        $_firstClosure = $items->first(null, static fn (): int => 1);
        /** @psalm-check-type-exact $_firstClosure = 1|string */

        $_firstFiltered = $items->first(static fn (string $s): bool => $s !== '', static fn (): null => null);
        /** @psalm-check-type-exact $_firstFiltered = string|null */

        $_getClosure = $items->get(0, static fn (): int => 1);
        /** @psalm-check-type-exact $_getClosure = 1|string */
    }

    /**
     * @param LazyCollection<int, string> $lazy
     * @param Enumerable<int, string> $enumerable
     */
    public function otherReceivers(LazyCollection $lazy, Enumerable $enumerable): void
    {
        $_lazyClosure = $lazy->first(null, static fn (): int => 1);
        /** @psalm-check-type-exact $_lazyClosure = 1|string */

        $_lazyScalar = $lazy->first(null, 1);
        /** @psalm-check-type-exact $_lazyScalar = 1|string */

        $_enumerableClosure = $enumerable->first(null, static fn (): int => 1);
        /** @psalm-check-type-exact $_enumerableClosure = 1|string */

        $_enumerableScalar = $enumerable->first(null, 1);
        /** @psalm-check-type-exact $_enumerableScalar = 1|string */
    }
}
?>
--EXPECTF--
