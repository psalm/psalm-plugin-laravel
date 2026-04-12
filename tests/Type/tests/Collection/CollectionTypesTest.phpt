--FILE--
<?php declare(strict_types=1);

namespace App\Models;

use App\Models\Customer;

final class CustomerRepository
{
    /** @return \Illuminate\Database\Eloquent\Collection<int, Customer> */
    public function getAll(): \Illuminate\Database\Eloquent\Collection
    {
      return Customer::all();
    }

    public function getFirst(): ?Customer
    {
      return $this->getAll()->first();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Customer> */
    public function getBuilder(array $attributes): \Illuminate\Database\Eloquent\Builder
    {
      return Customer::where($attributes);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Customer> */
    public function getWhereUsingLessMagic(array $attributes): \Illuminate\Database\Eloquent\Collection
    {
      return Customer::query()->where($attributes)->get();
    }

    /**
     * Eloquent\Collection::empty() resolves static<never, never> through inheritance.
     * @psalm-check-type-exact $empty = \Illuminate\Database\Eloquent\Collection<never, never>
     */
    public function emptyEloquentCollection(): \Illuminate\Database\Eloquent\Collection
    {
      $empty = \Illuminate\Database\Eloquent\Collection::empty();

      return $empty;
    }
}
?>
--EXPECTF--
