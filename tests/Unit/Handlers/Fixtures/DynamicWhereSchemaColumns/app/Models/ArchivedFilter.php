<?php

declare(strict_types=1);

namespace DynamicWhereFixture\Models;

use Illuminate\Database\Eloquent\Model;

/** Same table as {@see UserFilter}: both alternatives of a related-model union accept `user_id`. */
final class ArchivedFilter extends Model
{
    protected $table = 'user_filters';
}
