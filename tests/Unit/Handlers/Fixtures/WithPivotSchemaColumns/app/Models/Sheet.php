<?php

declare(strict_types=1);

namespace WithPivotFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** Renames the created column: a plain pivot gates its date casts on its OWN `created_at`, not on `made_at`. */
class Sheet extends Model
{
    public const CREATED_AT = 'made_at';

    public function plainStamps(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'sheet_entries')->withTimestamps();
    }

    public function plainStampsWithCreatedAt(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'sheet_entries')->withTimestamps()->withPivot('created_at');
    }

    /** A `using()` pivot hydrates raw: `made_at` and the default `updated_at` are the parent's dates, `created_at` is not. */
    public function rawEntries(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'sheet_entries')->using(Pivot::class)->withPivot('hours', 'made_at', 'created_at', 'updated_at');
    }
}
