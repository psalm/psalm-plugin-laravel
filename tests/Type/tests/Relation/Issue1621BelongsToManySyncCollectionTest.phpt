--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;
use App\Models\MechanicSpecialization;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

// Laravel types sync()'s $ids as a bare `\Illuminate\Support\Collection|Model|array|int|string`.
// Psalm expands the bare Collection to Collection<array-key, mixed>, and Collection's templates are
// invariant, so a Collection<int, int> (e.g. from ->pluck('id')) fell through to the `string` arm
// and raised ImplicitToStringCast. The sync family declares method-level templates instead.

/**
 * @param Collection<int, int> $ids
 * @return array{attached: array, detached: array, updated: array}
 */
function sync_int_collection(Mechanic $mechanic, Collection $ids): array
{
    return $mechanic->specializations()->sync($ids);
}

/**
 * @param Collection<int, int> $ids
 * @return array{attached: array, detached: array, updated: array}
 */
function sync_without_detaching_int_collection(Mechanic $mechanic, Collection $ids): array
{
    return $mechanic->specializations()->syncWithoutDetaching($ids);
}

/**
 * @param Collection<string, array{note: string}> $ids
 * @return array{attached: array, detached: array, updated: array}
 */
function sync_with_pivot_attributes_collection(Mechanic $mechanic, Collection $ids): array
{
    return $mechanic->specializations()->sync($ids, false);
}

/**
 * @param Collection<int, int> $ids
 * @return array{attached: array, detached: array, updated: array}
 */
function sync_with_pivot_values_int_collection(Mechanic $mechanic, Collection $ids): array
{
    return $mechanic->specializations()->syncWithPivotValues($ids, ['note' => 'x']);
}

// Previously accepted inputs keep type-checking, and the return shape is preserved.

/** @param EloquentCollection<int, MechanicSpecialization> $models */
function sync_still_accepts_other_inputs(Mechanic $mechanic, EloquentCollection $models): void
{
    $relation = $mechanic->specializations();

    $_ = $relation->sync([1, 2]);
    /** @psalm-check-type-exact $_ = array{attached: array<array-key, mixed>, detached: array<array-key, mixed>, updated: array<array-key, mixed>} */

    $relation->sync([1 => ['note' => 'x']]);
    $relation->sync(1);
    $relation->sync('uuid');
    $relation->sync(new MechanicSpecialization());
    $relation->sync($models);
    $relation->syncWithoutDetaching($models);
}
?>
--EXPECTF--
