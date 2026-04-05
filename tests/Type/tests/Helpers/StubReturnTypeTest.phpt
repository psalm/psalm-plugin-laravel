--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Tests for stub return types and @psalm-variadic annotations.
 */

function test_e_returns_string(): string
{
    return e('<script>alert("xss")</script>');
}

function test_model_load_returns_this(): Customer
{
    return (new Customer())->load('vehicles');
}

function test_model_load_variadic(): Customer
{
    return (new Customer())->load('vehicles', 'workOrders');
}

function test_model_load_missing_returns_this(): Customer
{
    return (new Customer())->loadMissing('vehicles');
}

function test_model_load_count_returns_this(): Customer
{
    return (new Customer())->loadCount('vehicles');
}

function test_model_make_visible_returns_this(): Customer
{
    return (new Customer())->makeVisible('email');
}

function test_model_make_visible_variadic(): Customer
{
    return (new Customer())->makeVisible('email', 'phone');
}

function test_model_make_hidden_returns_this(): Customer
{
    return (new Customer())->makeHidden('email');
}

function test_model_make_hidden_variadic(): Customer
{
    return (new Customer())->makeHidden('email', 'phone');
}

function test_query_builder_add_select(QueryBuilder $builder): QueryBuilder
{
    return $builder->addSelect('id', 'name');
}

function test_query_builder_distinct(QueryBuilder $builder): QueryBuilder
{
    return $builder->distinct();
}

/**
 * @param BelongsToMany<Customer, Customer> $relation
 * @return BelongsToMany<Customer, Customer>
 */
function test_belongs_to_many_with_pivot(BelongsToMany $relation): BelongsToMany
{
    return $relation->withPivot('role', 'created_at');
}

/** @param Collection<int, Customer> $collection */
function test_collection_load(Collection $collection): Collection
{
    return $collection->load('vehicles');
}

/** @param Collection<int, Customer> $collection */
function test_collection_load_variadic(Collection $collection): Collection
{
    return $collection->load('vehicles', 'workOrders');
}

/** @param Collection<int, Customer> $collection */
function test_collection_load_with_callback(Collection $collection): Collection
{
    return $collection->load(['vehicles' => function (\Illuminate\Database\Eloquent\Relations\Relation $query) {
        $query->getBaseQuery();
    }]);
}

/** @param Collection<int, Customer> $collection */
function test_collection_load_missing_variadic(Collection $collection): Collection
{
    return $collection->loadMissing('vehicles', 'workOrders');
}

/** @return Builder<Customer> */
function test_builder_without_variadic(): Builder
{
    return Customer::query()->without('vehicles', 'workOrders');
}

/** @return Builder<Customer> */
function test_builder_with_count_variadic(): Builder
{
    return Customer::query()->withCount('vehicles', 'workOrders');
}

/** @return Builder<Customer> */
function test_builder_with_callback(): Builder
{
    return Customer::query()->with('vehicles', function (\Illuminate\Database\Eloquent\Relations\Relation $query) {
        $query->getBaseQuery();
    });
}
?>
--EXPECT--
