<?php

declare(strict_types=1);

namespace KnownAttributeFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Post extends Model
{
    protected $table = 'posts';

    /** @var list<string> */
    protected $fillable = ['author_id', 'title', 'body'];

    /**
     * Declared through a `newEloquentBuilder()` override (the detection form that exists at the
     * Laravel 12.20 floor; `#[UseEloquentBuilder]` is newer).
     *
     * @param \Illuminate\Database\Query\Builder $query
     */
    #[\Override]
    public function newEloquentBuilder($query): PostBuilder
    {
        return new PostBuilder($query);
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withPivot('pivot_col');
    }
}
