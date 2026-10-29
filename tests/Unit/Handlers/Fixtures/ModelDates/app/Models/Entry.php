<?php

declare(strict_types=1);

namespace ModelDatesFixture\Models;

use Illuminate\Database\Eloquent\Model;

/** A renamed created column and a null updated column: `getDates()` is `['created', null]`. */
class Entry extends Model
{
    public const CREATED_AT = 'created';

    public const UPDATED_AT = null;
}
