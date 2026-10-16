--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\LazyCollection;

/**
 * Laravel declares one default template as `TDefault|(\Closure(): TDefault)`, so passing a Closure
 * binds the template to the Closure itself and the result type contains `Closure(): T`.
 * `last()`, `pull()`, `value()` and `get()` use the same conditional split as `first()`:
 * a Closure default contributes what it returns (plus `TValue`, since the closure only runs when
 * nothing matches), any other default contributes itself.
 */
final class CollectionClosureDefaultTest
{
    /** @param Collection<int, int> $items */
    public function last(Collection $items): void
    {
        $_noDefault = $items->last();
        /** @psalm-check-type-exact $_noDefault = int|null */

        $_scalar = $items->last(null, 'none');
        /** @psalm-check-type-exact $_scalar = 'none'|int */

        $_closure = $items->last(null, static fn (): string => 'none');
        /** @psalm-check-type-exact $_closure = 'none'|int */

        $_filtered = $items->last(static fn (int $i): bool => $i > 1, static fn (): null => null);
        /** @psalm-check-type-exact $_filtered = int|null */
    }

    /** @param Collection<string, int> $items */
    public function pull(Collection $items): void
    {
        $_scalar = $items->pull('a', 'none');
        /** @psalm-check-type-exact $_scalar = 'none'|int */

        $_closure = $items->pull('a', static fn (): string => 'none');
        /** @psalm-check-type-exact $_closure = 'none'|int */
    }

    /** @param Collection<int, array<string, int>> $items */
    public function value(Collection $items): void
    {
        $_closure = $items->value('a', static fn (): string => 'none');
        /** @psalm-check-type-exact $_closure = 'none'|mixed */
    }

    /**
     * @param LazyCollection<int, int> $lazy
     * @param Enumerable<int, int> $enumerable
     */
    public function get(LazyCollection $lazy, Enumerable $enumerable): void
    {
        $_lazyScalar = $lazy->get(1, 'none');
        /** @psalm-check-type-exact $_lazyScalar = 'none'|int */

        $_lazyClosure = $lazy->get(1, static fn (): string => 'none');
        /** @psalm-check-type-exact $_lazyClosure = 'none'|int */

        $_lazyNullKey = $lazy->get(null, static fn (): string => 'none');
        /** @psalm-check-type-exact $_lazyNullKey = 'none'|int */

        $_enumerableClosure = $enumerable->get(1, static fn (): string => 'none');
        /** @psalm-check-type-exact $_enumerableClosure = 'none'|int */
    }

    /** @param EloquentCollection<int, Customer> $customers */
    public function eloquentCollection(EloquentCollection $customers): void
    {
        $_last = $customers->last(null, static fn (): string => 'none');
        /** @psalm-check-type-exact $_last = 'none'|Customer */

        $_pull = $customers->pull(0, static fn (): string => 'none');
        /** @psalm-check-type-exact $_pull = 'none'|Customer */
    }
}
?>
--EXPECTF--
