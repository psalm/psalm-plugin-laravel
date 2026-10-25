<?php

declare(strict_types=1);

namespace AppendsFixture\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The one model that must be reported: `avatar_url` is appended but has no accessor and no cast, so
 * serializing an instance throws BadMethodCallException.
 */
class UnbackedAppendModel extends Model
{
    /** @var list<string> */
    protected $appends = ['avatar_url'];
}
