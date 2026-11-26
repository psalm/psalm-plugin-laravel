--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

// Known false positive of the relation-name sinks (caveat in the Model::loadMorph() stub docblock).
// The `$relations` argument of loadMorph() and its variants maps a morph class to relation names.
// Laravel calls only the names; the keys just look up the class. Psalm cannot sink the values of
// an array without its keys, so a request-chosen key is reported like a relation name. Expect no
// finding here once the sink can be limited to the values.

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;

final class RnmPost extends Model {}

final class RnmComment extends Model
{
    /** @return MorphTo<Model, $this> */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}

function morphKeyFromInput(Request $request, RnmComment $comment): void
{
    $comment->loadMorph('commentable', [(string) $request->input('type') => ['comments']]);
}
?>
--EXPECTF--
TaintedCallable on line %d: Detected tainted text
