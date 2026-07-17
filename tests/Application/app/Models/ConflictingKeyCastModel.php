<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A cast on the primary-key column that conflicts with $keyType — getKey() must fall back to
 * the stub's `int|string` rather than trust a mapped type the cast contradicts.
 */
final class ConflictingKeyCastModel extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'id' => 'string',
    ];
}
