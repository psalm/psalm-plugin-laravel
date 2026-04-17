--FILE--
<?php declare(strict_types=1);

use App\Collections\DamageReportCollection;
use App\Collections\PartCollection;
use App\Collections\WorkOrderCollection;
use App\Models\Customer;
use App\Models\DamageReport;
use App\Models\Part;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Models with custom collections should return the custom collection type
 * from Builder methods, Relation methods, and Model::all().
 *
 * Three detection patterns are tested:
 * 1. #[CollectedBy] attribute (WorkOrder model)
 * 2. newCollection() override with native return type (Part model)
 * 3. protected static string $collectionClass property (DamageReport model)
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/622
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/658
 */

// === #[CollectedBy] attribute (WorkOrder model) ===

// --- Builder::get() ---

/** @param Builder<WorkOrder> $builder */
function test_builder_get(Builder $builder): WorkOrderCollection
{
    /** @psalm-check-type-exact $result = WorkOrderCollection<int, WorkOrder> */
    $result = $builder->get();
    return $result;
}

// --- Builder::findMany() ---

/** @param Builder<WorkOrder> $builder */
function test_builder_findMany(Builder $builder): WorkOrderCollection
{
    /** @psalm-check-type-exact $result = WorkOrderCollection<int, WorkOrder> */
    $result = $builder->findMany([1, 2, 3]);
    return $result;
}

// --- Builder::hydrate() ---

/** @param Builder<WorkOrder> $builder */
function test_builder_hydrate(Builder $builder): WorkOrderCollection
{
    /** @psalm-check-type-exact $result = WorkOrderCollection<int, WorkOrder> */
    $result = $builder->hydrate([]);
    return $result;
}

// --- Builder::fromQuery() ---

/** @param Builder<WorkOrder> $builder */
function test_builder_fromQuery(Builder $builder): WorkOrderCollection
{
    /** @psalm-check-type-exact $result = WorkOrderCollection<int, WorkOrder> */
    $result = $builder->fromQuery('SELECT * FROM work_orders');
    return $result;
}

// --- Model::all() ---

function test_model_all(): WorkOrderCollection
{
    /** @psalm-check-type-exact $result = WorkOrderCollection<int, WorkOrder> */
    $result = WorkOrder::all();
    return $result;
}

// --- Static call forwarded to Builder: Model::where()->get() ---

function test_static_get(): WorkOrderCollection
{
    /** @psalm-check-type-exact $result = WorkOrderCollection<int, WorkOrder> */
    $result = WorkOrder::where('status', 'completed')->get();
    return $result;
}

// --- Custom collection methods are available ---

/** @param Builder<WorkOrder> $builder */
function test_custom_method_available(Builder $builder): WorkOrderCollection
{
    $collection = $builder->get();
    /** @psalm-check-type-exact $completed = WorkOrderCollection<int, WorkOrder>&static */
    $completed = $collection->completed();
    return $completed;
}

// === newCollection() override (Part model) ===

/** @param Builder<Part> $builder */
function test_newCollection_builder_get(Builder $builder): PartCollection
{
    /** @psalm-check-type-exact $result = PartCollection<int, Part> */
    $result = $builder->get();
    return $result;
}

function test_newCollection_model_all(): PartCollection
{
    /** @psalm-check-type-exact $result = PartCollection<int, Part> */
    $result = Part::all();
    return $result;
}

// === $collectionClass property (DamageReport model) ===

/** @param Builder<DamageReport> $builder */
function test_collectionClass_builder_get(Builder $builder): DamageReportCollection
{
    /** @psalm-check-type-exact $result = DamageReportCollection<int, DamageReport> */
    $result = $builder->get();
    return $result;
}

function test_collectionClass_model_all(): DamageReportCollection
{
    /** @psalm-check-type-exact $result = DamageReportCollection<int, DamageReport> */
    $result = DamageReport::all();
    return $result;
}

// === Relation method calls should also return custom collection (#658) ===
// Repair shop domain: Vehicle -> HasMany -> WorkOrder (WorkOrderCollection via #[CollectedBy])

// --- HasMany::get() on a model with custom collection ---

/** @param \Illuminate\Database\Eloquent\Relations\HasMany<WorkOrder, Vehicle> $relation */
function test_relation_get_custom_collection(\Illuminate\Database\Eloquent\Relations\HasMany $relation): WorkOrderCollection
{
    /** @psalm-check-type-exact $result = WorkOrderCollection<int, WorkOrder> */
    $result = $relation->get();
    return $result;
}

// --- BelongsToMany::get() on a model with custom collection ---

/** @param \Illuminate\Database\Eloquent\Relations\BelongsToMany<Part, \App\Models\Shop, Pivot, 'pivot'> $relation */
function test_belongsToMany_get_custom_collection(\Illuminate\Database\Eloquent\Relations\BelongsToMany $relation): PartCollection
{
    /** @psalm-check-type-exact $result = PartCollection<int, Part> */
    $result = $relation->get();
    return $result;
}

// --- BelongsToMany::findMany() on a model with custom collection ---

/** @param \Illuminate\Database\Eloquent\Relations\BelongsToMany<Part, \App\Models\Shop, Pivot, 'pivot'> $relation */
function test_belongsToMany_findMany_custom_collection(\Illuminate\Database\Eloquent\Relations\BelongsToMany $relation): PartCollection
{
    /** @psalm-check-type-exact $result = PartCollection<int, Part> */
    $result = $relation->findMany([1, 2, 3]);
    return $result;
}

// --- MorphToMany::get() on a model with custom collection ---

/** @param \Illuminate\Database\Eloquent\Relations\MorphToMany<WorkOrder, \App\Models\Shop, MorphPivot, 'pivot'> $relation */
function test_morphToMany_get_custom_collection(\Illuminate\Database\Eloquent\Relations\MorphToMany $relation): WorkOrderCollection
{
    /** @psalm-check-type-exact $result = WorkOrderCollection<int, WorkOrder> */
    $result = $relation->get();
    return $result;
}

// --- Relation::get() on model WITHOUT custom collection stays Collection ---

/** @param \Illuminate\Database\Eloquent\Relations\HasMany<Vehicle, Customer> $relation */
function test_relation_get_default_collection(\Illuminate\Database\Eloquent\Relations\HasMany $relation): Collection
{
    /** @psalm-check-type-exact $result = Collection<int, Vehicle> */
    $result = $relation->get();
    return $result;
}

// --- MorphTo::get() on a union related model must not narrow to a single custom collection ---

/** @param \Illuminate\Database\Eloquent\Relations\MorphTo<Customer|WorkOrder, DamageReport> $relation */
function test_morphTo_get_union_related_collection(\Illuminate\Database\Eloquent\Relations\MorphTo $relation): Collection
{
    /** @psalm-check-type-exact $result = Collection<int, Customer|WorkOrder> */
    $result = $relation->get();
    return $result;
}

// === Models WITHOUT custom collection still return Eloquent\Collection ===

/** @param Builder<Customer> $builder */
function test_default_collection(Builder $builder): Collection
{
    /** @psalm-check-type-exact $result = Collection<int, Customer> */
    $result = $builder->get();
    return $result;
}

function test_default_collection_all(): Collection
{
    /** @psalm-check-type-exact $result = Collection<int, Customer> */
    $result = Customer::all();
    return $result;
}
?>
--EXPECTF--
