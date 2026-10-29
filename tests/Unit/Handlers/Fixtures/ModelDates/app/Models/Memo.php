<?php

declare(strict_types=1);

namespace ModelDatesFixture\Models;

use Illuminate\Database\Eloquent\Model;

/** An explicit cast on a timestamp column wins over the implicit date cast. */
class Memo extends Model
{
    protected $casts = [
        'created_at' => 'immutable_datetime',
    ];
}
