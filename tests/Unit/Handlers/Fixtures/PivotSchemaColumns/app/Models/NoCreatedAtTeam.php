<?php

declare(strict_types=1);

namespace PivotSchemaFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The parent has no created-at column, but a plain pivot still selects the literal `created_at` column
 * (which opens the date-cast gate) while only the parent's `updated_at` is in the cast set.
 */
class NoCreatedAtTeam extends Model
{
    public const CREATED_AT = null;

    protected $table = 'no_created_at_teams';

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'no_created_at_team_user')->withPivot('role', 'created_at', 'updated_at');
    }
}
