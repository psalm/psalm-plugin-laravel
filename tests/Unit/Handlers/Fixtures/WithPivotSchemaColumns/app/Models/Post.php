<?php

declare(strict_types=1);

namespace WithPivotFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Date casts on a non-null column, a nullable one with a format, and an immutable one; SoftDeletes adds `deleted_at`. */
class Post extends Model
{
    use SoftDeletes;

    protected $casts = [
        'published_at' => 'datetime',
        'reviewed_on' => 'datetime:Y-m-d',
        'archived_at' => 'immutable_datetime',
    ];
}
