<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Model;

class TraitScopedPost extends Model
{
    use HasJoinScope;

    protected $table = 'posts';
}
