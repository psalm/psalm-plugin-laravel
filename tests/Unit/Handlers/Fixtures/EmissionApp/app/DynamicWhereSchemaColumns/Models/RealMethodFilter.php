<?php

declare(strict_types=1);

namespace DynamicWhereFixture\Models;

use Illuminate\Database\Eloquent\Model;

final class RealMethodFilter extends Model
{
    protected $table = 'user_filters';

    /** A real method shadows the schema-derived dynamic where for the `user_id` column. */
    public static function whereUserId(string $id): int
    {
        return \strlen($id);
    }
}
