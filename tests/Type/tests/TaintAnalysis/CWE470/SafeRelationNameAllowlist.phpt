--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

// CWE-470 negative cases for the relation-name sinks (see TaintedCallableRelationName.phpt).
// A relation name the code wrote, or request input narrowed to an allowlist, must not raise
// TaintedCallable: Psalm drops input taint from a value typed as literal strings, and the
// validation `in:` rule escapes it. Request input elsewhere in the call (a constraint closure
// body, a column) is not a relation name.
//
// The stub sinks are shared with the rest of the batch, so an empty expectation against them
// alone proves nothing (docs/contributing/taint-analysis.md, "Testing-time pitfall"). Each
// allowlisted value also reaches srnSink(), a per-file sink specialized per call, and the
// unguarded control below shows that the same input reports through it.

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;

final class SrnComment extends Model {}

final class SrnAuthor extends Model
{
    /** @return HasMany<SrnComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(SrnComment::class);
    }
}

enum SrnInclude: string
{
    case Comments = 'comments';
}

/**
 * @psalm-taint-specialize
 * @psalm-taint-sink callable $name
 */
function srnSink(mixed $name): bool { return $name !== null; }

function srnUnguardedControl(Request $request): void
{
    srnSink((string) $request->input('include'));
}

/** @param Builder<SrnAuthor> $query */
function literalNames(Request $request, Builder $query, SrnAuthor $author): void
{
    $query->with('comments');
    $query->with(['comments' => static fn (HasMany $q): HasMany => $q->where('body', (string) $request->input('body'))]);
    $query->with('comments', static fn (HasMany $q): HasMany => $q->latest());
    $query->whereHas('comments', static fn (Builder $q): Builder => $q->where('body', (string) $request->input('body')));
    $author->load('comments');
}

/** @param Builder<SrnAuthor> $query */
function inArrayAllowlist(Request $request, Builder $query): void
{
    $include = (string) $request->input('include');
    if (in_array($include, ['comments'], true)) {
        $query->with($include);
        srnSink($include);
    }
}

/** @param Builder<SrnAuthor> $query */
function filteredListAllowlist(Request $request, Builder $query): void
{
    $includes = array_filter(
        explode(',', (string) $request->input('include')),
        static fn (string $name): bool => in_array($name, ['comments'], true),
    );
    $query->with($includes);
    srnSink($includes);
}

/** @param Builder<SrnAuthor> $query */
function intersectAllowlist(Request $request, Builder $query): void
{
    // The allowlist goes first: array_intersect() keeps the values (and the type) of its first argument.
    $includes = array_values(array_intersect(['comments'], explode(',', (string) $request->input('include'))));
    $query->with($includes);
    srnSink($includes);
}

/** @param Builder<SrnAuthor> $query */
function matchAllowlist(Request $request, Builder $query): void
{
    $include = match ((string) $request->input('include')) {
        'comments' => 'comments',
        default => throw new \InvalidArgumentException('Unknown include.'),
    };
    $query->with($include);
    srnSink($include);
}

/** @param Builder<SrnAuthor> $query */
function enumAllowlist(Request $request, Builder $query): void
{
    $include = SrnInclude::from((string) $request->input('include'))->value;
    $query->with($include);
    srnSink($include);
}

/** @param Builder<SrnAuthor> $query */
function validatedAllowlist(Request $request, Builder $query): void
{
    $data = $request->validate(['include' => 'required|string|in:comments']);
    $query->with($data['include']);
    srnSink($data['include']);
}
?>
--EXPECTF--
TaintedCallable on line %d: Detected tainted text
