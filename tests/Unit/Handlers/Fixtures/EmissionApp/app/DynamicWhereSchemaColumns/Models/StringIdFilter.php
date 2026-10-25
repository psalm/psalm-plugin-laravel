<?php

declare(strict_types=1);

namespace DynamicWhereFixture\Models;

use Illuminate\Database\Eloquent\Model;

/** `user_id` is a string here and an integer on {@see UserFilter}. */
final class StringIdFilter extends Model
{
    protected $table = 'string_filters';
}
