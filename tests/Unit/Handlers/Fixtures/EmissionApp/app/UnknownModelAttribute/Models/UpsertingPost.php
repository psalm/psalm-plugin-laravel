<?php

declare(strict_types=1);

namespace KnownAttributeFixture\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Declares its own `upsert()`, which shadows the `__callStatic()` forwarding to the builder: the keys
 * are no longer known to be raw column names.
 */
class UpsertingPost extends Model
{
    protected $table = 'posts';

    /** @param array<string, mixed> $values */
    public static function upsert(array $values, string $uniqueBy): int
    {
        return \count($values) + \strlen($uniqueBy);
    }
}
