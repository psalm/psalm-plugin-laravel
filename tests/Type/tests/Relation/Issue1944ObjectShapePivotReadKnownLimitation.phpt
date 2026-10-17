--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

final class ShapePivotRelated extends Model {}

// Relation result shapes are carried by WithPivotReturnTypeHandler; property reads still depend on Psalm's intersection handling.
/** @see \Psalm\LaravelPlugin\Handlers\Eloquent\WithPivotReturnTypeHandler */

/** @param Pivot&object{role: string} $pivot */
function issue1944_direct_shape_read(Pivot $pivot): mixed
{
    return $pivot->role;
}

/** @param BelongsToMany<ShapePivotRelated, Model, Pivot&object{role: string}, 'pivot'> $relation */
function issue1944_related_model_shape_read(BelongsToMany $relation): mixed
{
    return $relation->firstOrFail()->pivot->role;
}
?>
--EXPECTF--
UndefinedMagicPropertyFetch on line %d: Magic instance property Illuminate\Database\Eloquent\Relations\Pivot::$role is not defined
MixedPropertyFetch on line %d: Cannot fetch property on mixed var $relation->firstorfail()->pivot
UndefinedMagicPropertyFetch on line %d: Magic instance property ShapePivotRelated::$pivot is not defined
