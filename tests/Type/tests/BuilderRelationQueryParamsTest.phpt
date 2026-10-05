--FILE--
<?php declare(strict_types=1);

// Regression for https://github.com/psalm/psalm-plugin-laravel/issues/1668: relation-query params
// on Eloquent\Builder must accept everything Laravel accepts (a Relation instance, an Expression
// $count, the full $column union) and the withWhereHas() callback must not promise a Builder.
namespace Tests\Psalm\LaravelPlugin\Sandbox;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

final class RelQueryComment extends Model {}

final class RelQueryPost extends Model
{
    /** @return HasMany<RelQueryComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(RelQueryComment::class);
    }
}

/** @param Builder<RelQueryPost> $q */
function relation_query_params(Builder $q, RelQueryPost $p): void
{
    $q->whereHas($p->comments());
    $q->orWhereHas($p->comments());
    $q->orHas($p->comments());
    $q->doesntHave($p->comments());
    $q->orDoesntHave($p->comments());
    $q->whereDoesntHave($p->comments());
    $q->orWhereDoesntHave($p->comments());
    $q->whereRelation($p->comments(), 'title', 'x');
    $q->orWhereRelation($p->comments(), 'title', 'x');
    $q->whereRelation('comments', DB::raw('lower(title)'), 'x');
    $q->orWhereRelation('comments', ['approved' => true]);
    $q->has('comments', '>', new Expression('5'));
    $q->orHas('comments', '>', new Expression('5'));
    $q->whereHas('comments', null, '>', new Expression('5'));
    $q->orWhereHas('comments', null, '>', new Expression('5'));
    $q->hasMorph('comments', ['x'], '>', new Expression('5'));
    $q->orHasMorph('comments', ['x'], '>', new Expression('5'));
    $q->whereHasMorph('comments', ['x'], null, '>', new Expression('5'));
    $q->orWhereHasMorph('comments', ['x'], null, '>', new Expression('5'));
}

/** @param Builder<RelQueryPost> $q */
function with_where_has_callback(Builder $q): void
{
    // untyped $q is the supported shape
    $q->withWhereHas('comments', function ($c): mixed { return $c; });
    $q->withWhereHas('comments', fn ($c) => $c);

    // the callback also runs as an eager-load constraint with a Relation: Builder-typed param is unsafe
    $q->withWhereHas('comments', function (Builder $c): Builder { return $c; });
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 2 of Illuminate\Database\Eloquent\Builder::withWhereHas expects Closure[impure](Illuminate\Database\Eloquent\Builder<Illuminate\Database\Eloquent\Model>|Illuminate\Database\Eloquent\Relations\Relation<Illuminate\Database\Eloquent\Model, Illuminate\Database\Eloquent\Model, mixed>):mixed|null, but Closure[pure](Illuminate\Database\Eloquent\Builder):Illuminate\Database\Eloquent\Builder<Illuminate\Database\Eloquent\Model> provided
