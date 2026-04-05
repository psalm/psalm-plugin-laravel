--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Verify that relation stubs accept multi-param generics matching Laravel's native annotations.
 * HasOne<Related, Declaring>, HasManyThrough<Related, Intermediate, Declaring>, etc.
 */

function test_hasOne_returns_typed_relation(): HasOne
{
    return (new WorkOrder())->invoice();
}

function test_belongsToMany_returns_typed_relation(): BelongsToMany
{
    return (new WorkOrder())->parts();
}

function test_hasManyThrough_returns_typed_relation(): HasManyThrough
{
    return (new Customer())->workOrders();
}

/**
 * When the generic type is explicitly annotated, getRelated()/getParent() resolve correctly.
 * Psalm cannot yet infer full generic params through trait methods, so @var is used to
 * verify template parameter propagation through the relation API.
 */
function test_hasOne_getRelated_returns_invoice(): Invoice
{
    /** @var HasOne<Invoice, WorkOrder> $relation */
    $relation = (new WorkOrder())->invoice();
    /** @psalm-check-type-exact $relation = HasOne<Invoice, WorkOrder> */
    return $relation->getRelated();
}

function test_hasOne_getParent_returns_workOrder(): WorkOrder
{
    /** @var HasOne<Invoice, WorkOrder> $relation */
    $relation = (new WorkOrder())->invoice();
    return $relation->getParent();
}
/**
 * Relation method return types: fluent methods return self, terminal methods return models/collections.
 */

function test_latest_preserves_relation_type(): HasOne
{
    /** @var HasOne<Invoice, WorkOrder> $relation */
    $relation = (new WorkOrder())->invoice();
    /** @psalm-check-type-exact $latest = HasOne<Invoice, WorkOrder>&static */
    $latest = $relation->latest();
    return $latest;
}

/** @return \App\Collections\InvoiceCollection */
function test_get_returns_collection(): \App\Collections\InvoiceCollection
{
    /** @var HasOne<Invoice, WorkOrder> $relation */
    $relation = (new WorkOrder())->invoice();
    /** @psalm-check-type-exact $collection = \App\Collections\InvoiceCollection */
    $collection = $relation->get();
    return $collection;
}

function test_sole_returns_model(): Invoice
{
    /** @var HasOne<Invoice, WorkOrder> $relation */
    $relation = (new WorkOrder())->invoice();
    /** @psalm-check-type-exact $model = Invoice */
    $model = $relation->sole();
    return $model;
}

/**
 * Relation::select() accepts variadic string arguments (forwarded to Query\Builder::select).
 * Laravel uses func_get_args() so callers can write ->select('id', 'name') instead of ->select(['id', 'name']).
 */
function test_select_variadic_on_relation(): HasOne
{
    /** @var HasOne<Invoice, WorkOrder> $relation */
    $relation = (new WorkOrder())->invoice();
    return $relation->select('id', 'name', 'created_at');
}

// where(), orderBy(), and chained calls on Relations are tested in
// ForwardingHandlerTest.phpt — the MethodForwardingHandler now
// preserves Relation generic types for both mixin and __call paths.
?>
--EXPECTF--
