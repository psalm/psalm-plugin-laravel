--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;
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
?>
--EXPECTF--
