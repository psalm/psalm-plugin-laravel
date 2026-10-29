<?php

declare(strict_types=1);

namespace ModelDatesFixture\Models;

use Illuminate\Database\Eloquent\Model;

/** A NOT NULL date column: Eloquent's pluck() leaves a key raw, so it is still a string key. */
class Ledger extends Model
{
    public const CREATED_AT = 'logged_at';

    public const UPDATED_AT = null;
}
