<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TrashedPost extends Model
{
    use SoftDeletes;

    protected $table = 'posts';
}
