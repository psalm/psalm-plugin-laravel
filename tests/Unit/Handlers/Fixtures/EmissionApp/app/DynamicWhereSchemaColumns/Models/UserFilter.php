<?php

declare(strict_types=1);

namespace DynamicWhereFixture\Models;

use Illuminate\Database\Eloquent\Model;

/** No `@property` docblock: every column is known only from the migration. */
class UserFilter extends Model
{
    protected $table = 'user_filters';
}
