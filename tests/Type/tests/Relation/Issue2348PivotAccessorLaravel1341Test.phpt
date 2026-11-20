--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.41.0');
--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;

/**
 * Surfaces Laravel shapes only from 13.41 on (`object{TAccessor: TPivotModel}` docblocks) also carry the `as()`
 * accessor (#2348). A `chunkMap()` callback value keeps its own `pivot` (KnownLimitation: the identity callback's
 * param is typed before the call resolves).
 */
function accessor_renames_laravel_13_41_surfaces(Mechanic $mechanic): void
{
    $relation = $mechanic->specializationsAccessorOnly();

    $_get = $relation->get();
    /** @psalm-trace $_get */

    $_get_item = $relation->get()->firstOrFail();
    /** @psalm-trace $_get_item */

    $_create_quietly = $relation->createQuietly(['name' => 'x']);
    /** @psalm-trace $_create_quietly */

    $_chunk_map_identity = $relation->chunkMap(static fn ($specialization) => $specialization);
    /** @psalm-trace $_chunk_map_identity */
}
?>
--EXPECTF--
Trace on line %d: $_get: Illuminate\Database\Eloquent\Collection<int, App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}>
Trace on line %d: $_get_item: App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_create_quietly: App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_chunk_map_identity: Illuminate\Support\Collection<int, App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}>
