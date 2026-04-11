--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;
use App\Models\MechanicSpecialization;
use App\Models\SpecializationPivot;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Regression tests for https://github.com/psalm/psalm-plugin-laravel/issues/709
 *
 * BelongsToMany now declares 4 template params (TRelatedModel, TDeclaringModel,
 * TPivotModel, TAccessor) matching Laravel's native annotations. Previously the
 * stub declared only 2 params, causing TooManyTemplateParams for the native
 * 4-param usage and stripping pivot types from method return types.
 */

// --- No TooManyTemplateParams for 4-param generics ---

/**
 * 4-param annotation must be accepted without TooManyTemplateParams.
 *
 * @param BelongsToMany<MechanicSpecialization, Mechanic, SpecializationPivot, 'pivot'> $relation
 * @return BelongsToMany<MechanicSpecialization, Mechanic, SpecializationPivot, 'pivot'>
 */
function test_four_template_params_accepted(BelongsToMany $relation): BelongsToMany
{
    return $relation;
}

// --- Return types carry the pivot intersection ---

/**
 * first() return type must include the pivot intersection.
 *
 * @param BelongsToMany<MechanicSpecialization, Mechanic, SpecializationPivot, 'pivot'> $relation
 */
function test_first_infers_pivot_intersection(BelongsToMany $relation): void
{
    $_ = $relation->first();
    /** @psalm-check-type-exact $_ = MechanicSpecialization&object{pivot: SpecializationPivot}|null */
}

/**
 * firstOrFail() return type must include the pivot intersection (non-null).
 *
 * @param BelongsToMany<MechanicSpecialization, Mechanic, SpecializationPivot, 'pivot'> $relation
 */
function test_firstOrFail_infers_pivot_intersection(BelongsToMany $relation): void
{
    $_ = $relation->firstOrFail();
    /** @psalm-check-type-exact $_ = MechanicSpecialization&object{pivot: SpecializationPivot} */
}

/**
 * create() return type must include the pivot intersection.
 *
 * @param BelongsToMany<MechanicSpecialization, Mechanic, SpecializationPivot, 'pivot'> $relation
 */
function test_create_infers_pivot_intersection(BelongsToMany $relation): void
{
    $_ = $relation->create(['name' => 'Brakes']);
    /** @psalm-check-type-exact $_ = MechanicSpecialization&object{pivot: SpecializationPivot} */
}

// --- using() is callable and accepts a class-string<TPivotModel> ---

/**
 * using() must accept a class-string of a Pivot subclass.
 * The @psalm-this-out annotation is present for future Psalm narrowing support;
 * in Psalm 7, TPivotModel narrowing via @psalm-this-out is not yet fully
 * propagated through generic params.
 *
 * @param BelongsToMany<MechanicSpecialization, Mechanic, Pivot, 'pivot'> $relation
 */
function test_using_accepts_pivot_class(BelongsToMany $relation): BelongsToMany
{
    return $relation->using(SpecializationPivot::class);
}

/**
 * Model method with 4-param annotation is callable and assignable to BelongsToMany.
 */
function test_model_method_with_4_params_returns_correctly(): BelongsToMany
{
    return (new Mechanic())->specializationsWithPivot();
}
?>
--EXPECTF--
