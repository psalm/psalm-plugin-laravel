<?php

declare(strict_types=1);

namespace ModelDatesFixture\Models;

use Illuminate\Database\Eloquent\Model;

/** Default timestamps and no `@property`: `created_at`/`updated_at` are dates although nothing casts them. */
class Post extends Model
{
    protected $casts = [
        'published_at' => 'datetime',
    ];
}
