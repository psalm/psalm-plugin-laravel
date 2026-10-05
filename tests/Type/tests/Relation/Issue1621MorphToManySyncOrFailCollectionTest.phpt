--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.0.0');
--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;
use Illuminate\Support\Collection;

// The sync*OrFail() methods on MorphToMany, see Issue1621MorphToManySyncCollectionTest.

/**
 * @param Collection<int, int> $ids
 * @return array{attached: array, detached: array, updated: array}
 */
function morph_sync_or_fail_int_collection(Mechanic $mechanic, Collection $ids): array
{
    return $mechanic->workOrderTagsAccessorOnly()->syncOrFail($ids);
}

/**
 * @param Collection<int, int> $ids
 * @return array{attached: array, detached: array, updated: array}
 */
function morph_sync_without_detaching_or_fail_int_collection(Mechanic $mechanic, Collection $ids): array
{
    return $mechanic->workOrderTagsAccessorOnly()->syncWithoutDetachingOrFail($ids);
}

/**
 * @param Collection<int, int> $ids
 * @return array{attached: array, detached: array, updated: array}
 */
function morph_sync_with_pivot_values_or_fail_int_collection(Mechanic $mechanic, Collection $ids): array
{
    return $mechanic->workOrderTagsAccessorOnly()->syncWithPivotValuesOrFail($ids, ['note' => 'x']);
}
?>
--EXPECTF--
