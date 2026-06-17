--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection as SupportCollection;
use App\Models\Customer;
use App\Models\Vehicle;

/**
 * @mixin Builder<Customer>
 */
final class NonEloquentBuilderMixin {}

final class EloquentBuilderCustomerRepository
{
    /** @return Builder<Customer> */
    public function getNewQuery(): Builder
    {
        $query = (new Customer())->newQuery();
        /** @psalm-check-type-exact $query = Builder<Customer&static> */
        return $query;
    }

    /** @return Builder<Customer> */
    public function getNewModelQuery(): Builder
    {
        $query = (new Customer())->newModelQuery();
        /** @psalm-check-type-exact $query = Builder<Customer&static> */
        return $query;
    }

    /** @param Builder<Customer> $builder */
    public function firstOrFailFromBuilderInstance(Builder $builder): Customer {
        return $builder->firstOrFail();
    }

    /** @param Builder<Customer> $builder */
    public function findOrFailFromBuilderInstance(Builder $builder): Customer {
        return $builder->findOrFail(1);
    }

    /**
    * @param Builder<Customer> $builder
    * @return Collection<int, Customer>
    */
    public function findMultipleOrFailFromBuilderInstance(Builder $builder): Collection {
        return $builder->findOrFail([1, 2]);
    }

    /** @param Builder<Customer> $builder */
    public function findOne(Builder $builder): ?Customer {
        return $builder->find(1);
    }

    /** @param Builder<Customer> $builder */
    public function findViaArray(Builder $builder): Collection {
        return $builder->find([1]);
    }

    /** @return Builder<Customer> */
    public function getWhereBuilderViaInstance(array $attributes): Builder {
        $query = (new Customer())->where($attributes);
        /** @psalm-check-type-exact $query = Builder<Customer> */
        return $query;
    }

    public function chunkReturnsTemplatedCollection(): void
    {
        Customer::query()
            ->chunk(10, function (Collection $collection) {
                /** @psalm-check-type-exact $collection = \Illuminate\Database\Eloquent\Collection<int, \App\Models\Customer> */
                echo $collection->count();
            });
    }

    /**
     * paginate() resolves to the concrete Illuminate\Pagination class (issue #1052),
     * so the concrete-only getCollection() resolves on the result (subsumes #978).
     *
     * @return LengthAwarePaginator<int, Customer>
     */
    public function testPaginate(): LengthAwarePaginator
    {
        $paginator = Customer::query()->paginate();
        /** @psalm-check-type-exact $paginator = LengthAwarePaginator<int, Customer> */

        $_collection = $paginator->getCollection();
        /** @psalm-check-type-exact $_collection = SupportCollection<int, Customer> */

        return $paginator;
    }

    /** @return Paginator<int, Customer> */
    public function testSimplePaginate(): Paginator
    {
        $paginator = Customer::query()->simplePaginate();
        /** @psalm-check-type-exact $paginator = Paginator<int, Customer> */

        return $paginator;
    }

    /** @return CursorPaginator<int, Customer> */
    public function testCursorPaginate(Builder $builder): CursorPaginator
    {
        $paginator = Customer::query()->cursorPaginate();
        /** @psalm-check-type-exact $paginator = CursorPaginator<int, Customer> */

        return $paginator;
    }

    /** @return Builder<Customer> */
    public function getWhereBuilderViaStatic(array $attributes): Builder
    {
      $query = Customer::where($attributes);
      /** @psalm-check-type-exact $query = Builder<Customer> */
      return $query;
    }

    public function setModelChangesBuilderTemplate(): void
    {
        $_result = Customer::query()->setModel(new Vehicle());
        /** @psalm-check-type-exact $_result = Builder<Vehicle>&static */
    }

//    /** @return Collection<int, Customer> */
//    public function getWhereViaStatic(array $attributes): Collection
//    {
//      return Customer::where($attributes)->get();
//    }
}

/**
* @psalm-param Builder<Customer> $builder
* @psalm-return Builder<Customer>
*/
function can_call_methods_on_underlying_query_builder(Builder $builder): Builder {
    return $builder->orderBy('id', 'ASC');
}

function test_whereDateWithDateTimeInterface(Builder $builder): Builder {
    return $builder->whereDate('created_at', '>', new \DateTimeImmutable());
}

function test_whereDateWithString(Builder $builder): Builder {
    return $builder->whereDate('created_at', '>', (new \DateTimeImmutable())->format('d/m/Y'));
}

function test_whereDateWithNull(Builder $builder): Builder
{
    return $builder->whereDate('created_at', '>', null);
}

function test_whereDateWithInt(Builder $builder): Builder
{
    return $builder->whereDate('created_at', '>', 1);
}

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/776
 * Arrow-function closure returns the chained Builder (`mixed`-compatible),
 * not `void` or `static`. Must not raise InvalidArgument.
 */
function test_where_arrow_closure(): Builder
{
    return Customer::query()->where(fn ($q) => $q->where('email', 'x')->orWhere('name', 'y'));
}

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/776
 * Long-form closure with explicit `void` return must remain accepted.
 */
function test_where_long_form_closure(): Builder
{
    return Customer::query()->where(function ($q): void {
        $q->where('email', 'x')->orWhere('name', 'y');
    });
}

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/776
 * Same closure shape on firstWhere (a sibling stub fixed in the same PR).
 */
function test_firstWhere_arrow_closure(): ?Customer
{
    return Customer::query()->firstWhere(fn ($q) => $q->where('email', 'x'));
}

function test_non_eloquent_mixin_does_not_use_model_builder_forwarding(): void
{
    $_result = (new NonEloquentBuilderMixin())->where(['id' => 1]);
    /** @psalm-check-type-exact $_result = NonEloquentBuilderMixin */
}

function test_scopes_on_builder_contract_preserves_concrete_builder_surface(BuilderContract $query): BuilderContract
{
    $_result = $query->scopes(['active'])->whereNull('deleted_at');
    /** @psalm-check-type-exact $_result = Builder<Model>&static */
    return $_result;
}

/**
 * paginate() accepts a Closure $perPage and the fifth $total argument (Laravel v13).
 * These named-argument forms must type-check without TooManyArguments / InvalidArgument.
 */
function test_paginate_accepts_total_and_closure(): void
{
    $_total = Customer::query()->paginate(total: fn (): int => 100);
    /** @psalm-check-type-exact $_total = LengthAwarePaginator<int, Customer> */

    $_closure = Customer::query()->paginate(perPage: fn (int $total): int => $total, total: 100);
    /** @psalm-check-type-exact $_closure = LengthAwarePaginator<int, Customer> */
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 3 of Illuminate\Database\Eloquent\Builder::whereDate expects DateTimeInterface|null|string, but 1 provided
