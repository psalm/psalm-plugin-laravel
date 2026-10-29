<?php

declare(strict_types=1);

namespace ModelDatesFixture\Models;

use Illuminate\Database\Eloquent\Model;

/** No timestamps: `getDates()` is empty, so the created/updated columns keep their raw schema type. */
class Snapshot extends Model
{
    public $timestamps = false;
}
