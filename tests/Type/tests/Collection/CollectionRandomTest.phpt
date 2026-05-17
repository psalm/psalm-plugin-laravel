--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Regression guard: ensure Laravel's own random() docblock keeps working without
 * a plugin stub override. See #903 for the conditional-return narrowing issue.
 */
final class CollectionRandomTest
{
    /** @return EloquentCollection<int, Customer> */
    public function customers(): EloquentCollection
    {
        return Customer::all();
    }

    /** @psalm-check-type-exact $single = Customer */
    public function noArg(): Customer
    {
        $single = $this->customers()->random();

        return $single;
    }

    public function callableReceivesWholeCollection(): void
    {
        $this->customers()->random(fn (BaseCollection $items): int => min(5, $items->count()));
    }

    public function preserveKeysArgCompiles(): void
    {
        $this->customers()->random(3, true);
    }
}
?>
--EXPECTF--
