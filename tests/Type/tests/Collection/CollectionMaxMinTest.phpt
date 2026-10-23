--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\LazyCollection;

/**
 * Psalm drops Laravel's reflected `max()`/`min()` conditional, which left both `mixed`.
 * The stubs restate it with `is null` and `is string` first:
 *  - no callback: `TValue|null`
 *  - callable: `TMaxResult|null`
 *  - string column: `mixed` (stays untyped for plain collections)
 * The Eloquent aggregate handler still narrows a known model column.
 */
final class CollectionMaxMinTest
{
    /** @param Collection<int, int> $items */
    public function collection(Collection $items): void
    {
        $_max = $items->max();
        /** @psalm-check-type-exact $_max = int|null */

        $_min = $items->min();
        /** @psalm-check-type-exact $_min = int|null */

        $_maxFn = $items->max(static fn (int $i): float => $i / 2);
        /** @psalm-check-type-exact $_maxFn = float|null */

        $_minFn = $items->min(static fn (int $i): string => (string) $i);
        /** @psalm-check-type-exact $_minFn = decimal-int-string|null */

        $_column = $items->max('column');
        /** @psalm-check-type-exact $_column = mixed */
    }

    /** @param LazyCollection<int, int> $items */
    public function lazyCollection(LazyCollection $items): void
    {
        $_max = $items->max();
        /** @psalm-check-type-exact $_max = int|null */

        $_minFn = $items->min(static fn (int $i): int => $i);
        /** @psalm-check-type-exact $_minFn = int|null */
    }

    /** @param Enumerable<int, int> $items */
    public function enumerable(Enumerable $items): void
    {
        $_max = $items->max();
        /** @psalm-check-type-exact $_max = int|null */

        $_minFn = $items->min(static fn (int $i): int => $i);
        /** @psalm-check-type-exact $_minFn = int|null */
    }

    /** @param EloquentCollection<int, Customer> $customers */
    public function eloquentCollection(EloquentCollection $customers): void
    {
        $_first = $customers->max();
        /** @psalm-check-type-exact $_first = Customer|null */

        $_id = $customers->max('id');
        /** @psalm-check-type-exact $_id = string|null */
    }
}
?>
--EXPECTF--
