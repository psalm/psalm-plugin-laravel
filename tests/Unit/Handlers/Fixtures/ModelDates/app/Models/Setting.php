<?php

declare(strict_types=1);

namespace ModelDatesFixture\Models;

use Illuminate\Database\Eloquent\Casts\AsStringable;
use Illuminate\Database\Eloquent\Model;
use ModelDatesFixture\Enums\Level;

/** Pluck keys whose cast changes the PHP key type: only a date object may be turned into a string key. */
class Setting extends Model
{
    public $timestamps = false;

    protected $casts = [
        'flag' => 'int',
        'level' => Level::class,
        'sku' => AsStringable::class,
    ];
}
