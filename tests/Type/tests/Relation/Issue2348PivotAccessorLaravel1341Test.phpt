--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.41.0');
--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;

/**
 * Surfaces Laravel shapes only from 13.41 on (`object{TAccessor: TPivotModel}` docblocks) also carry the `as()`
 * accessor (#2348).
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
}
?>
--EXPECTF--
Trace on line %d: $_get: Illuminate\Database\Eloquent\Collection<int, App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}>
Trace on line %d: $_get_item: App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_create_quietly: App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}
