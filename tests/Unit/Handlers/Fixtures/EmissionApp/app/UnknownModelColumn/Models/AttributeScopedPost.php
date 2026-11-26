<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;

#[ScopedBy(JoinScope::class)]
class AttributeScopedPost extends Model
{
    protected $table = 'posts';
}
