<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Model;

class ExtendedPost extends Model
{
    protected $table = 'posts';

    #[\Override]
    protected static function booted(): void
    {
        static::addGlobalScope(new ExtendingScope());
    }
}
