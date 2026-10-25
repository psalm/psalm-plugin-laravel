<?php

declare(strict_types=1);

namespace WithPivotFixture\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/** `kind` is a cast on a column of its own table, `rank` one on a column the table lacks. */
class CasterScore extends Pivot
{
    protected $table = 'scores';

    protected $casts = ['kind' => ValueCaster::class, 'rank' => ValueCaster::class];
}
