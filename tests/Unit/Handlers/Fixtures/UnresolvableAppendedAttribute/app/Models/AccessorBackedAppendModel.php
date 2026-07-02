<?php

declare(strict_types=1);

namespace AppendsFixture\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Clean: `full_name` is backed by a legacy accessor (separator-insensitive match), so it is not
 * reported.
 */
class AccessorBackedAppendModel extends Model
{
    /** @var list<string> */
    protected $appends = ['full_name'];

    protected function getFullNameAttribute(): string
    {
        return 'first last';
    }
}
