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

// --- using() narrows TPivotModel on the returned relation ---

/**
 * using() accepts a class-string of a Pivot subclass and re-narrows TPivotModel via the
 * stub's `@return static<...>` (#1088). The related/declaring models and the 'pivot'
 * accessor are preserved; only the pivot slot changes.
 *
 * @param BelongsToMany<MechanicSpecialization, Mechanic, Pivot, 'pivot'> $relation
 */
function test_using_narrows_pivot_class(BelongsToMany $relation): BelongsToMany
{
    $narrowed = $relation->using(SpecializationPivot::class);
    /** @psalm-check-type-exact $narrowed = BelongsToMany<MechanicSpecialization, Mechanic, SpecializationPivot, 'pivot'>&static */

    return $narrowed;
}

/**
 * Model method with 4-param annotation is callable and assignable to BelongsToMany.
 */
function test_model_method_with_4_params_returns_correctly(): BelongsToMany
{
    return (new Mechanic())->specializationsWithPivot();
}

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/776
 * Arrow-function closure on BelongsToMany::firstWhere must not raise InvalidArgument.
 *
 * @param BelongsToMany<MechanicSpecialization, Mechanic, SpecializationPivot, 'pivot'> $relation
 */
function test_belongsToMany_firstWhere_arrow_closure(BelongsToMany $relation): void
{
    $_ = $relation->firstWhere(fn ($q) => $q->where('name', 'x'));
    /** @psalm-check-type-exact $_ = MechanicSpecialization&object{pivot: SpecializationPivot}|null */
}
?>
--EXPECTF--
