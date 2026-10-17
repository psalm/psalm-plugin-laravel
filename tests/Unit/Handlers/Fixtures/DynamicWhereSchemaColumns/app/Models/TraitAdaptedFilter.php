<?php

declare(strict_types=1);

namespace DynamicWhereFixture\Models;

use DynamicWhereFixture\Concerns\WhereState;
use Illuminate\Database\Eloquent\Model;

final class TraitAdaptedFilter extends Model
{
    use WhereState {
        whereState as private;
    }

    protected $table = 'user_filters';
}
