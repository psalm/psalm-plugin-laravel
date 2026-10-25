--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox\PivotShapeVariance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * TPivotModel is covariant (#1944): a pivot refined with the `withPivot()` columns
 * (`Pivot&object{...}`) must still satisfy every existing `Pivot` / `MorphPivot` declaration.
 */
class Tag extends Model {}

class Post extends Model {}

/**
 * @param BelongsToMany<Tag, Post, Pivot, 'pivot'> $relation
 * @return BelongsToMany<Tag, Post, Pivot, 'pivot'>
 */
function takes_plain(BelongsToMany $relation): BelongsToMany
{
    return $relation;
}

/**
 * @param MorphToMany<Tag, Post, MorphPivot, 'pivot'> $relation
 * @return MorphToMany<Tag, Post, MorphPivot, 'pivot'>
 */
function takes_plain_morph(MorphToMany $relation): MorphToMany
{
    return $relation;
}

/**
 * @param BelongsToMany<Tag, Post, Pivot&object{role: string}, 'pivot'> $relation
 * @return BelongsToMany<Tag, Post, Pivot, 'pivot'>
 */
function refined_belongs_to_many(BelongsToMany $relation): BelongsToMany
{
    takes_plain($relation);

    return $relation;
}

/**
 * @param MorphToMany<Tag, Post, MorphPivot&object{role: string}, 'pivot'> $relation
 * @return MorphToMany<Tag, Post, MorphPivot, 'pivot'>
 */
function refined_morph_to_many(MorphToMany $relation): MorphToMany
{
    takes_plain_morph($relation);
    takes_plain($relation);

    return $relation;
}
?>
--EXPECTF--
