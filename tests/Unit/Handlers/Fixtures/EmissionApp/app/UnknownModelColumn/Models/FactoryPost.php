<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FactoryPost extends Model
{
    protected $table = 'posts';

    /**
     * Returns the stock builder, already joined: every query of the model starts joined.
     *
     * @return Builder<static>
     */
    #[\Override]
    public function newEloquentBuilder($query): Builder
    {
        return new Builder($query->join('authors', 'authors.id', '=', 'posts.author_id'));
    }
}
