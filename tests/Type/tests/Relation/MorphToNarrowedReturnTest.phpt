--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/913
 *
 * morphTo() sub-case of #913. Two distinct gaps stacked here:
 *
 *   1. (FIXED on this branch) morphTo() used to be stubbed MorphTo<Model, $this>, and the `$this`
 *      template arg collapsed the whole return to `mixed` even on a bare `return $this->morphTo();`.
 *      The `$this` -> `static` stub fix clears that collapse.
 *   2. (PINNED below) with the collapse gone, a method narrowing the related type
 *      (MorphTo<Image|Video, self>) raises MoreSpecificReturnType / LessSpecificReturnStatement,
 *      because the stub can only honestly return MorphTo<Model, static> — the concrete morph target
 *      is resolved at runtime via the morph map. Inherent to polymorphic relations; resolvable
 *      app-side (widen the annotation) or by a future morph-map-aware resolver. Tracked by the
 *      @todo on HasRelationships::morphTo(). This pins the current diagnostic so it flips when that
 *      resolver lands.
 */
class Image extends Model
{
}

class Video extends Model
{
}

class Comment extends Model
{
    /** @return MorphTo<Image|Video, self> */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}

?>
--EXPECTF--
MoreSpecificReturnType on line %d: The declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\Image|Tests\Psalm\LaravelPlugin\Sandbox\Video, Tests\Psalm\LaravelPlugin\Sandbox\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\Comment::commentable is more specific than the inferred return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\Comment&static>'
LessSpecificReturnStatement on line %d: The type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\Comment&static>' is more general than the declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\Image|Tests\Psalm\LaravelPlugin\Sandbox\Video, Tests\Psalm\LaravelPlugin\Sandbox\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\Comment::commentable
