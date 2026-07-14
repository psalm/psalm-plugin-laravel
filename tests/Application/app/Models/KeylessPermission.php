<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Query-only model backed by a table whose rows have no single Eloquent key.
 *
 * The missing `$incrementing = false` declaration is intentional: it reproduces
 * the configuration reported in issue #1260.
 */
final class KeylessPermission extends Model
{
    protected $table = 'keyless_permissions';

    protected $primaryKey;

    /** @var array<string, string> */
    protected $casts = [
        'allowed' => 'bool',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
