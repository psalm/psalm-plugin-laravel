<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Model;

class FactoryBuilderPost extends Model
{
    protected $table = 'posts';

    #[\Override]
    public function newEloquentBuilder($query): FactoryBuilder
    {
        return new FactoryBuilder($query->join('authors', 'authors.id', '=', 'posts.author_id'));
    }
}
