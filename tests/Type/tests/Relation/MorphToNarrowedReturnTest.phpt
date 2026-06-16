--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/913
 *
 * morphTo() sub-case of #913. Two distinct gaps stack here:
 *
 *   1. CURRENT (master, pinned below): morphTo() is stubbed MorphTo<Model, $this>, and the `$this`
 *      template arg collapses the whole return to `mixed` even WITHOUT a chained method call — a
 *      bare `return $this->morphTo();` already raises MixedReturnStatement. (The rest of the family
 *      needs a chained call to collapse; morphTo degrades on the bare return because its sole stub
 *      type param is `$this`.)
 *   2. AFTER the `$this` -> `static` stub fix (open in #1055): the collapse clears, but a method
 *      narrowing the related type (MorphTo<Image|Video, self>) then raises MoreSpecificReturnType,
 *      because the stub can only honestly return MorphTo<Model, static> — the concrete target is
 *      resolved at runtime via the morph map. Inherent to polymorphic relations; resolvable
 *      app-side (widen the annotation) or by a future morph-map-aware resolver.
 *
 * Pins gap 1 (the current output) so it flips when the stub fix lands; gap 2 is pinned in #1055.
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
MixedReturnStatement on line %d: Could not infer a return type
