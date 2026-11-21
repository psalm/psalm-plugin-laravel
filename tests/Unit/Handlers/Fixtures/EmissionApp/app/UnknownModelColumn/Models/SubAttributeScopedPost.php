<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Model;

#[JoinScopeAttribute(JoinScope::class)]
class SubAttributeScopedPost extends Model
{
    protected $table = 'posts';
}
