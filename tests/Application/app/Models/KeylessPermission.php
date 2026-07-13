<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot-style model with no single primary key.
 *
 * The missing key name and disabled incrementing are a valid, internally consistent Eloquent
 * configuration for pivot/junction models.
 */
final class KeylessPermission extends Model
{
    protected $primaryKey;

    /** @var bool */
    public $incrementing = false;

    protected $casts = [
        'allowed' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
