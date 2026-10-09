--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

// MorphToMany inherits sync() from BelongsToMany, whose stub declares method-level templates.
// Laravel declares sync() in the InteractsWithPivotTable trait, so the subclass must still
// resolve the stubbed signature rather than the trait's bare `Collection` docblock.

/**
 * @param Collection<int, int> $ids
 * @return array{attached: array, detached: array, updated: array}
 */
function morph_sync_int_collection(Mechanic $mechanic, Collection $ids): array
{
    return $mechanic->workOrderTagsAccessorOnly()->sync($ids);
}

/**
 * @param Collection<int, int> $ids
 * @return array{attached: array, detached: array, updated: array}
 */
function morph_sync_without_detaching_int_collection(Mechanic $mechanic, Collection $ids): array
{
    return $mechanic->workOrderTagsAccessorOnly()->syncWithoutDetaching($ids);
}

/**
 * @param Collection<int, int> $ids
 * @return array{attached: array, detached: array, updated: array}
 */
function morph_sync_with_pivot_values_int_collection(Mechanic $mechanic, Collection $ids): array
{
    return $mechanic->workOrderTagsAccessorOnly()->syncWithPivotValues($ids, ['note' => 'x']);
}

// Collections of related models are accepted as well: Laravel maps each model to its related key.

/**
 * @param EloquentCollection<int, WorkOrder> $workOrders
 * @return array{attached: array, detached: array, updated: array}
 */
function morph_sync_eloquent_collection_of_models(Mechanic $mechanic, EloquentCollection $workOrders): array
{
    return $mechanic->workOrderTagsAccessorOnly()->sync($workOrders);
}

/**
 * @param Collection<int, WorkOrder> $workOrders
 * @return array{attached: array, detached: array, updated: array}
 */
function morph_sync_base_collection_of_models(Mechanic $mechanic, Collection $workOrders): array
{
    return $mechanic->workOrderTagsAccessorOnly()->sync($workOrders);
}
?>
--EXPECTF--
