--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.0.0');
--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;
use Illuminate\Support\Collection;

// The sync*OrFail() methods exist from Laravel 13.0. Same bare-Collection `$ids` docblock as sync():
// Collection<int, int> matched no arm of Collection<array-key, mixed>|Model|array(|int|string).

/**
 * @param Collection<int, int> $ids
 * @return array{attached: array, detached: array, updated: array}
 */
function sync_or_fail_int_collection(Mechanic $mechanic, Collection $ids): array
{
    return $mechanic->specializations()->syncOrFail($ids);
}

/**
 * @param Collection<int, int> $ids
 * @return array{attached: array, detached: array, updated: array}
 */
function sync_without_detaching_or_fail_int_collection(Mechanic $mechanic, Collection $ids): array
{
    return $mechanic->specializations()->syncWithoutDetachingOrFail($ids);
}

/**
 * @param Collection<int, int> $ids
 * @return array{attached: array, detached: array, updated: array}
 */
function sync_with_pivot_values_or_fail_int_collection(Mechanic $mechanic, Collection $ids): array
{
    return $mechanic->specializations()->syncWithPivotValuesOrFail($ids, ['note' => 'x']);
}
?>
--EXPECTF--
