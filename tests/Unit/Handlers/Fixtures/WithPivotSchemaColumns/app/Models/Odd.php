<?php

declare(strict_types=1);

namespace WithPivotFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Overrides the created column, so the parent's timestamp names are unknown. */
class Odd extends Model
{
    #[\Override]
    public function getCreatedAtColumn(): string
    {
        return 'made_at';
    }

    public function defaultStamps(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'sheet_entries')->withTimestamps();
    }

    public function stampsWithHours(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'sheet_entries')->withPivot('hours')->withTimestamps('made_at', 'updated_at');
    }
}
