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
 * the related model of `$this->morphTo()` is taken from the enclosing method's `@return MorphTo<X, …>`
 * unverified. The morph map decides the target at runtime, so nothing proves X; every `$this->morphTo()`
 * in such a method narrows to X, including one that is never returned.
 */
class Post extends Model
{
}

class Reaction extends Model
{
    /** @return MorphTo<Post, self> */
    public function target(): MorphTo
    {
        $_unrelated = $this->morphTo('origin');
        /** @psalm-check-type-exact $_unrelated = MorphTo<Post, Reaction&static> */

        return $this->morphTo();
    }
}
?>
--EXPECTF--
