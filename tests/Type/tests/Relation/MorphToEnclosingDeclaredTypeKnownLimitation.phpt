--FILE--
<?php declare(strict_types=1);

// Dedicated sub-namespace: psalm-tester batches every .phpt into one analysis.
namespace Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingLimits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1091
 *
 * Pins the accepted unsoundness documented on ModelRelationReturnTypeHandler::getEnclosingMorphToReturnType():
 * the related model of a returned `$this->morphTo()` is taken from the method's `@return MorphTo<X, …>`
 * unverified. The morph map decides the target at runtime, so nothing proves X: a declaration naming the
 * wrong model is believed, and callers then see X.
 */
class Post extends Model
{
}

function onlyPost(Post $_post): void
{
}

function caller(Reaction $reaction): void
{
    onlyPost($reaction->target()->getRelated());
}

class Reaction extends Model
{
    /** @return MorphTo<Post, self> */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }
}
?>
--EXPECTF--
