<?php

declare(strict_types=1);

namespace ModelDatesFixture\Models;

use Illuminate\Database\Eloquent\Model;

/** An immutable cast on a custom column, next to the implicit timestamp dates. */
class Invoice extends Model
{
    protected $casts = [
        'paid_at' => 'immutable_datetime',
    ];
}
