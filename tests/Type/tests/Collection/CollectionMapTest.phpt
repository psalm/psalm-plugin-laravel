--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Eloquent\Collection::map() uses a conditional return type:
 * - Returns static (Eloquent\Collection) when the callback produces Model instances
 * - Returns Support\Collection when the callback produces non-Model values (arrays, scalars)
 *
 * This mirrors Laravel's runtime behavior: map() calls toBase() when the result
 * contains non-Model values.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/500
 */
final class EloquentCollectionMapTest
{
    /** @return EloquentCollection<int, Customer> */
    public function getCustomers(): EloquentCollection
    {
        return Customer::all();
    }

    /**
     * Mapping models to arrays returns Support\Collection (non-Model branch).
     *
     * @psalm-check-type-exact $result = BaseCollection<int, array{id: string}>
     */
    public function mapToArrays(): BaseCollection
    {
        $result = $this->getCustomers()->map(fn (Customer $customer): array => [
            'id' => $customer->id,
        ]);

        return $result;
    }

    /**
     * Mapping models to integers returns Support\Collection (non-Model branch).
     *
     * @psalm-check-type-exact $result = BaseCollection<int, int>
     */
    public function mapToInts(): BaseCollection
    {
        $result = $this->getCustomers()->map(fn (Customer $customer): int => (int) $customer->id);

        return $result;
    }

    /**
     * Mapping models to models preserves Eloquent\Collection (Model branch).
     *
     * @psalm-check-type-exact $result = EloquentCollection<int, Customer>&static
     */
    public function mapToModels(): EloquentCollection
    {
        $result = $this->getCustomers()->map(fn (Customer $customer): Customer => $customer);

        return $result;
    }

    /**
     * mapWithKeys producing non-Model values returns Support\Collection.
     *
     * @psalm-check-type-exact $result = BaseCollection<string, int>
     */
    public function mapWithKeysToScalars(): BaseCollection
    {
        $result = $this->getCustomers()->mapWithKeys(fn (Customer $customer): array => [$customer->id => (int) $customer->id]);

        return $result;
    }

    /**
     * mapWithKeys producing Model values preserves Eloquent\Collection.
     *
     * @psalm-check-type-exact $result = EloquentCollection<string, Customer>&static
     */
    public function mapWithKeysToModels(): EloquentCollection
    {
        $result = $this->getCustomers()->mapWithKeys(fn (Customer $customer): array => [$customer->id => $customer]);

        return $result;
    }
}
?>
--EXPECTF--
