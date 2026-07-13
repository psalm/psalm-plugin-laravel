<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

final class ThrowingCustomTypesModel extends Model
{
    protected $table = 'throwing_custom_types_models';

    protected $casts = ['flag' => 'boolean'];

    /** Deliberately uninitialized so strict custom-type reflection fails. */
    protected static string $builder;
}
