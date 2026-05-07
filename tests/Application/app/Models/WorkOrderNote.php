<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Service note left on a {@see WorkOrder}, optionally replying to another note.
 * Forms a threaded discussion (mechanic asks the customer, customer replies, etc.),
 * so the table is self-referential through `reply_to_id`.
 *
 * Exercises relation factories called with `self::class` —
 * see {@see https://github.com/psalm/psalm-plugin-laravel/issues/879}.
 */
final class WorkOrderNote extends Model
{
    protected $table = 'work_order_notes';

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'reply_to_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }
}
