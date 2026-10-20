<?php

declare(strict_types=1);

namespace PivotShapeFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** Renames the created-at column: only a using() pivot (fromRawAttributes) switches the casts on from it. */
final class Dock extends Model
{
    public const CREATED_AT = 'added_at';

    /** Explicit `using(Pivot::class)` hydrates like any custom pivot. */
    public function explicitUsing(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')->using(Pivot::class)->withTimestamps();
    }

    public function plain(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')->withTimestamps();
    }

    /**
     * The chain is hidden behind a variable, so an explicit using(Pivot::class) cannot be ruled out: the default pivot
     * casts `updated_at` (its own created_at is selected), a using() pivot does not (the parent's is `added_at`).
     */
    public function held(): BelongsToMany
    {
        $relation = $this->belongsToMany(User::class, 'team_user')->using(Pivot::class);

        $result = $relation->withPivot('created_at', 'updated_at');
        /** @psalm-trace $result */

        return $result;
    }
}
