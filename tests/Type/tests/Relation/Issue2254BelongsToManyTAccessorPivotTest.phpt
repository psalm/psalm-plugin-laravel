--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.41.0');
--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;

/**
 * Laravel 13.41 changed the BelongsToMany docblocks from `object{pivot: TPivotModel}` to
 * `object{TAccessor: TPivotModel}`. Psalm reads `TAccessor` as a literal property name, so every method the
 * stubs do not re-declare lost `->pivot` (#2254). Traces, not check-type: Psalm compares object shapes inside
 * an intersection by name only, so check-type cannot pin the keys.
 */
function belongs_to_many_methods_keep_pivot_shape(Mechanic $mechanic): void
{
    $relation = $mechanic->specializations();

    $_get = $relation->get();
    /** @psalm-trace $_get */

    $_pivot = $relation->get()->first()?->pivot;
    /** @psalm-trace $_pivot */

    $_sole = $relation->findSole(1);
    /** @psalm-trace $_sole */

    $_restore_or_create = $relation->restoreOrCreate(['name' => 'x']);
    /** @psalm-trace $_restore_or_create */

    $_create_or_restore = $relation->createOrRestore(['name' => 'x']);
    /** @psalm-trace $_create_or_restore */

    $_increment_or_create = $relation->incrementOrCreate(['name' => 'x']);
    /** @psalm-trace $_increment_or_create */

    $_create_quietly = $relation->createQuietly(['name' => 'x']);
    /** @psalm-trace $_create_quietly */

    $_create_many_quietly = $relation->createManyQuietly([['name' => 'x']]);
    /** @psalm-trace $_create_many_quietly */

    $_force_create = $relation->forceCreate(['name' => 'x']);
    /** @psalm-trace $_force_create */

    $_force_create_quietly = $relation->forceCreateQuietly(['name' => 'x']);
    /** @psalm-trace $_force_create_quietly */

    $_chunk_map = $relation->chunkMap(static function ($specialization): object {
        /** @psalm-trace $specialization */
        return $specialization->pivot;
    });
    /** @psalm-trace $_chunk_map */
}
?>
--EXPECTF--
Trace on line %d: $_get: Illuminate\Database\Eloquent\Collection<int, App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}>
Trace on line %d: $_pivot: Illuminate\Database\Eloquent\Relations\Pivot|null
Trace on line %d: $_sole: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_restore_or_create: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_create_or_restore: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_increment_or_create: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_create_quietly: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_create_many_quietly: array<int, App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}>
Trace on line %d: $_force_create: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_force_create_quietly: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $specialization: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_chunk_map: Illuminate\Support\Collection<int, Illuminate\Database\Eloquent\Relations\Pivot>
