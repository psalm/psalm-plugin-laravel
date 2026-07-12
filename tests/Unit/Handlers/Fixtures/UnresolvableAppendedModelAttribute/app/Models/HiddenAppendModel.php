<?php

declare(strict_types=1);

namespace AppendsFixture\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Clean: `secret` is unbacked but also hidden, so Eloquent drops it from the appended set before the
 * throwing loop. It never fatals, so it must NOT be reported. End-to-end guard for the $hidden filter.
 */
class HiddenAppendModel extends Model
{
    /** @var list<string> */
    protected $appends = ['secret'];

    /** @var list<string> */
    protected $hidden = ['secret'];
}
