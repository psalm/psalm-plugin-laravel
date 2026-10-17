--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox\PivotCovariance;

use App\Models\Mechanic;
use App\Models\MechanicSpecialization;
use App\Models\SpecializationPivot;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * TPivotModel is `@template-covariant` in every BelongsToMany stub header and in MorphToMany (#1944).
 * The header Psalm uses depends on the installed Laravel (`stubs/common`, `stubs/13`, `stubs/13.5.0` each
 * re-declare the class and the newest one wins), so this file runs unconditionally: each cell of the CI
 * matrix pins the header it loads. Each widening below is rejected with InvalidReturnStatement when that
 * header declares the template invariant. The empty `--EXPECTF--` is the oracle.
 */

/**
 * @param BelongsToMany<MechanicSpecialization, Mechanic, Pivot&object{role: string}, 'pivot'> $relation
 * @return BelongsToMany<MechanicSpecialization, Mechanic, Pivot, 'pivot'>
 */
function shape_widens_to_pivot(BelongsToMany $relation): BelongsToMany
{
    return $relation;
}

/**
 * @param BelongsToMany<MechanicSpecialization, Mechanic, SpecializationPivot, 'pivot'> $relation
 * @return BelongsToMany<MechanicSpecialization, Mechanic, Pivot, 'pivot'>
 */
function custom_pivot_widens_to_pivot(BelongsToMany $relation): BelongsToMany
{
    return $relation;
}

/**
 * @param MorphToMany<MechanicSpecialization, Mechanic, MorphPivot&object{role: string}, 'pivot'> $relation
 * @return MorphToMany<MechanicSpecialization, Mechanic, MorphPivot, 'pivot'>
 */
function morph_shape_widens_to_morph_pivot(MorphToMany $relation): MorphToMany
{
    return $relation;
}

/**
 * MorphToMany extends BelongsToMany, so the widened pivot also has to cross the parent template list.
 *
 * @param MorphToMany<MechanicSpecialization, Mechanic, MorphPivot&object{role: string}, 'pivot'> $relation
 * @return BelongsToMany<MechanicSpecialization, Mechanic, Pivot, 'pivot'>
 */
function morph_shape_widens_to_belongs_to_many(MorphToMany $relation): BelongsToMany
{
    return $relation;
}
?>
--EXPECTF--
