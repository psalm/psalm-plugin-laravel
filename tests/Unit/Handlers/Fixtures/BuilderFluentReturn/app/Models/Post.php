<?php

declare(strict_types=1);

namespace BuilderFluentReturnFixture\Models;

use Illuminate\Database\Eloquent\Model;

final class Post extends Model
{
    /**
     * @param \Illuminate\Database\Query\Builder $query
     */
    public function newEloquentBuilder($query): PostBuilder
    {
        return new PostBuilder($query);
    }
}
