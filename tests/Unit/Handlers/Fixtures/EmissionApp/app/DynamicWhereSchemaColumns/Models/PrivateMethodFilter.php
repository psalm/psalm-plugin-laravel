<?php

declare(strict_types=1);

namespace DynamicWhereFixture\Models;

use Illuminate\Database\Eloquent\Model;

final class PrivateMethodFilter extends Model
{
    protected $table = 'user_filters';

    /** Inaccessible from outside, so PHP falls through to Model::__call and the dynamic where. */
    private function whereFilterType(string $type): string
    {
        return $type;
    }
}
