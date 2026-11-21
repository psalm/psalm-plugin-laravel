<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Model;

class PlainPost extends Model
{
    protected $table = 'posts';

    /** `self::` / `static::` roots resolve to the enclosing model. */
    public static function viaSelf(): ?self
    {
        return self::query()->first(['typocol_self']);
    }

    public static function viaStatic(): ?static
    {
        return static::where('title', 'x')->first(['typocol_static', 'title']);
    }
}
