<?php

declare(strict_types=1);

namespace DynamicWhereFixture\Models;

use DynamicWhereFixture\Builders\CustomFilterBuilder;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;

/** @mixin CustomFilterBuilder */
#[UseEloquentBuilder(CustomFilterBuilder::class)]
class CustomFilter extends Model
{
    protected $table = 'user_filters';
}
