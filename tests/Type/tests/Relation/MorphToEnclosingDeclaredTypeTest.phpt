--FILE--
<?php declare(strict_types=1);

// Dedicated sub-namespace: psalm-tester batches every .phpt into one analysis.
namespace Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1091
 *
 * `$this->morphTo()` inside a method declaring `@return MorphTo<X, …>` resolves to the declared
 * related model X instead of the stub's `MorphTo<Model, static>`, so narrowing the related side
 * no longer raises MoreSpecificReturnType / LessSpecificReturnStatement (or InvalidReturnType for
 * an intersection). The declaring-model slot still comes from the receiver.
 */
interface HasTitle
{
}

class Post extends Model implements HasTitle
{
}

class Video extends Model implements HasTitle
{
}

trait HasCommentable
{
    /** @return MorphTo<Post|Video, self> */
    public function traitHosted(): MorphTo
    {
        return $this->morphTo();
    }
}

class Comment extends Model
{
    use HasCommentable;

    /** @return MorphTo<Post|Video, self> */
    public function selfSlot(): MorphTo
    {
        $r = $this->morphTo();
        /** @psalm-check-type-exact $r = MorphTo<Post|Video, Comment&static> */
        return $r;
    }

    /** @return MorphTo<Post|Video, static> */
    public function staticSlot(): MorphTo
    {
        return $this->morphTo();
    }

    /** @psalm-return MorphTo<Post|Video, $this> */
    public function thisSlot(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Post|Video, Comment> */
    public function explicitSlot(): MorphTo
    {
        return $this->morphTo('commentable', 'commentable_type', 'commentable_id');
    }

    /** @return MorphTo<Post, self> */
    public function singleModel(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model&HasTitle, self> */
    public function intersection(): MorphTo
    {
        $r = $this->morphTo();
        /** @psalm-check-type-exact $r = MorphTo<Model&HasTitle, Comment&static> */
        return $r;
    }

    /** @return MorphTo<Post|Video, self> */
    public function chainTrashed(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    /** @return MorphTo<Post|Video, self> */
    public function chainScopes(): MorphTo
    {
        return $this->morphTo()->withoutGlobalScopes();
    }
}

final class FinalComment extends Model
{
    /** @return MorphTo<Post|Video, self> */
    public function commentable(): MorphTo
    {
        $r = $this->morphTo();
        /** @psalm-check-type-exact $r = MorphTo<Post|Video, FinalComment> */
        return $r;
    }
}

class PinnedComment extends Comment
{
    /** @return MorphTo<Video, self> */
    public function pinnedTarget(): MorphTo
    {
        $r = $this->morphTo();
        /** @psalm-check-type-exact $r = MorphTo<Video, PinnedComment&static> */
        return $r;
    }
}

/** The external view is Psalm's own reading of the declared return, untouched by the handler. */
function external_view(Comment $comment, PinnedComment $pinned): void
{
    $_rel = $comment->selfSlot();
    /** @psalm-check-type-exact $_rel = MorphTo<Post|Video, Comment> */

    $_related = $comment->intersection()->getRelated();
    /** @psalm-check-type-exact $_related = Model&HasTitle */

    $_inherited = $pinned->selfSlot();
    /** @psalm-check-type-exact $_inherited = MorphTo<Post|Video, Comment> */
}
?>
--EXPECTF--
