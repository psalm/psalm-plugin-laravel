<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Model;

class SettingPost extends Model
{
    protected $table = 'posts';

    /** A userland static `get()`: the argument is a settings key, not a column. */
    public static function get(string $key): ?string
    {
        return $key === '' ? null : $key;
    }
}
