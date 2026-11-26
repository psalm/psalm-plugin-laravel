<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Model;

class HookedPost extends Model
{
    protected $table = 'posts';

    /** Pre-11 style default join: every query of the model starts joined. */
    #[\Override]
    public function newQuery()
    {
        return parent::newQuery()->join('authors', 'authors.id', '=', 'posts.author_id');
    }
}
