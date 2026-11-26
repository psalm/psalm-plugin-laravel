--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

// CWE-470: a relation name is called as a method on a new model before Laravel checks that the
// result is a Relation. A request-controlled name that is not a relation reaches Model::__call()
// and runs the Builder method of that name: `?include=truncate` empties the table (the shape of
// GHSA-5wcm-3v2w-8362, still reachable from application code on every Laravel version).
// One flow per relation-name parameter family, on every receiver form.

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;

final class RnComment extends Model
{
    /** @return BelongsTo<RnAuthor, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(RnAuthor::class);
    }

    /** @return MorphTo<Model, $this> */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}

final class RnAuthor extends Model
{
    /** @return HasMany<RnComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(RnComment::class);
    }
}

function includes(Request $request): string
{
    return (string) $request->input('include');
}

/** @param Builder<RnAuthor> $query */
function eagerLoading(Request $request, Builder $query): void
{
    $query->with(explode(',', includes($request)));
    $query->with('comments', includes($request));
    $query->with([includes($request) => static fn (HasMany $q): HasMany => $q]);
    $query->withOnly(includes($request));
}

/** @param Builder<RnAuthor> $query */
function existenceQueries(Request $request, Builder $query): void
{
    $query->has(includes($request));
    $query->orHas(includes($request));
    $query->doesntHave(includes($request));
    $query->orDoesntHave(includes($request));
    $query->whereHas(includes($request));
    $query->orWhereHas(includes($request));
    $query->whereDoesntHave(includes($request));
    $query->orWhereDoesntHave(includes($request));
    $query->withWhereHas(includes($request));
}

/** @param Builder<RnAuthor> $query */
function relationWhereClauses(Request $request, Builder $query): void
{
    $query->whereRelation(includes($request), 'id', 1);
    $query->orWhereRelation(includes($request), 'id', 1);
    $query->withWhereRelation(includes($request), 'id', 1);
    $query->whereDoesntHaveRelation(includes($request), 'id', 1);
    $query->orWhereDoesntHaveRelation(includes($request), 'id', 1);
}

/** @param Builder<RnComment> $query */
function morphQueries(Request $request, Builder $query, RnAuthor $author): void
{
    $query->hasMorph(includes($request), '*');
    $query->orHasMorph(includes($request), '*');
    $query->doesntHaveMorph(includes($request), '*');
    $query->orDoesntHaveMorph(includes($request), '*');
    $query->whereHasMorph(includes($request), '*');
    $query->orWhereHasMorph(includes($request), '*');
    $query->whereDoesntHaveMorph(includes($request), '*');
    $query->orWhereDoesntHaveMorph(includes($request), '*');
    $query->whereMorphRelation(includes($request), '*', 'id', 1);
    $query->orWhereMorphRelation(includes($request), '*', 'id', 1);
    $query->whereMorphDoesntHaveRelation(includes($request), '*', 'id', 1);
    $query->orWhereMorphDoesntHaveRelation(includes($request), '*', 'id', 1);
    $query->whereMorphedTo(includes($request), $author);
    $query->whereNotMorphedTo(includes($request), $author);
    $query->orWhereMorphedTo(includes($request), $author);
    $query->orWhereNotMorphedTo(includes($request), $author);
}

/** @param Builder<RnComment> $query */
function belongsToName(Request $request, Builder $query, RnAuthor $author): void
{
    $query->whereBelongsTo($author, includes($request));
    $query->orWhereBelongsTo($author, includes($request));
}

/** @param Builder<RnAuthor> $query */
function aggregates(Request $request, Builder $query): void
{
    $query->withCount(includes($request));
    $query->withAggregate(includes($request), 'id', 'max');
    $query->withMax(includes($request), 'id');
    $query->withMin(includes($request), 'id');
    $query->withSum(includes($request), 'id');
    $query->withAvg(includes($request), 'id');
    $query->withExists(includes($request));
}

function forwardedForms(Request $request, RnAuthor $author): void
{
    RnAuthor::with(includes($request));
    RnAuthor::whereHas(includes($request));
    $author->comments()->with(includes($request));
    RnAuthor::query()->whereHas('comments', static fn (Builder $q): Builder => $q->with(includes($request)));
}

function modelLoading(Request $request, RnAuthor $author, RnComment $comment): void
{
    $author->load(includes($request));
    $author->loadMissing(includes($request));
    $author->loadCount(includes($request));
    $author->loadAggregate(includes($request), 'id', 'max');
    $author->loadMax(includes($request), 'id');
    $author->loadMin(includes($request), 'id');
    $author->loadSum(includes($request), 'id');
    $author->loadAvg(includes($request), 'id');
    $author->loadExists(includes($request));
    $comment->loadMorph(includes($request), []);
    $comment->loadMorph('commentable', [RnAuthor::class => [includes($request)]]);
    $comment->loadMorphCount('commentable', [RnAuthor::class => [includes($request)]]);
    $comment->loadMorphAggregate('commentable', [RnAuthor::class => [includes($request)]], 'id', 'max');
    $comment->loadMorphMax('commentable', [RnAuthor::class => [includes($request)]], 'id');
    $comment->loadMorphMin('commentable', [RnAuthor::class => [includes($request)]], 'id');
    $comment->loadMorphSum('commentable', [RnAuthor::class => [includes($request)]], 'id');
    $comment->loadMorphAvg('commentable', [RnAuthor::class => [includes($request)]], 'id');
    $author->through(includes($request));
    $author->fresh(includes($request));
}

/**
 * @param Collection<int, RnAuthor> $authors
 * @param Collection<int, RnComment> $comments
 */
function collectionLoading(Request $request, Collection $authors, Collection $comments): void
{
    $authors->load(includes($request));
    $authors->loadMissing(includes($request));
    $authors->loadCount(includes($request));
    $authors->loadAggregate(includes($request), 'id', 'max');
    $authors->loadMax(includes($request), 'id');
    $authors->loadMin(includes($request), 'id');
    $authors->loadSum(includes($request), 'id');
    $authors->loadAvg(includes($request), 'id');
    $authors->loadExists(includes($request));
    $comments->loadMorph(includes($request), []);
    $comments->loadMorph('commentable', [RnAuthor::class => [includes($request)]]);
    $comments->loadMorphCount('commentable', [RnAuthor::class => [includes($request)]]);
    $authors->fresh(includes($request));
}
?>
--EXPECTF--
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
