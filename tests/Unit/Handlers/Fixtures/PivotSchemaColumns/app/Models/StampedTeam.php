<?php

declare(strict_types=1);

namespace PivotSchemaFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Renamed timestamp columns on the PARENT model. A plain `Pivot` only date-casts once its own literal
 * `created_at` column is selected, so `withTimestamps()` here reads the migration's column types; a
 * `using()` pivot evaluates the gate against the parent's `made_at` and casts both columns to Carbon.
 */
class StampedTeam extends Model
{
    public const CREATED_AT = 'made_at';

    public const UPDATED_AT = 'changed_at';

    protected $table = 'stamped_teams';

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'stamped_team_user')->withTimestamps();
    }

    public function customMembers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'stamped_team_user')->using(StampedPivot::class)->withTimestamps();
    }

    public function traceInBody(): void
    {
        $inBodyStamped = $this->belongsToMany(User::class, 'stamped_team_user')->withTimestamps();
        /** @psalm-trace $inBodyStamped */
        $inBodyStampedUsing = $this->belongsToMany(User::class, 'stamped_team_user')->using(StampedPivot::class)->withTimestamps();
        /** @psalm-trace $inBodyStampedUsing */

        return;
    }
}
