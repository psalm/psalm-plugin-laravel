<?php

declare(strict_types=1);

namespace WithPivotFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Renames the updated column: the pivot casts `modified_at`, not `updated_at`, once `created_at` is selected. */
class Ledger extends Model
{
    public const UPDATED_AT = 'modified_at';

    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ledger_entries')->withPivot('created_at', 'modified_at', 'updated_at', 'amount');
    }

    public function entriesAtCallSite(): BelongsToMany
    {
        $relation = $this->belongsToMany(User::class, 'ledger_entries')->withPivot('created_at', 'modified_at', 'updated_at', 'amount');
        /** @psalm-trace $relation */

        return $relation;
    }
}
