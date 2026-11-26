<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;

#[UseEloquentBuilder(OverridingBuilder::class)]
class OverriddenPost extends Model
{
    protected $table = 'posts';
}
