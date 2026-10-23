<?php

declare(strict_types=1);

namespace WithPivotFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Renames the created column: a plain pivot gates its date casts on its OWN `created_at`, a using() pivot on `made_at`. */
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

    public function rotaStamps(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'sheet_entries')->withTimestamps()->using(Rota::class);
    }
}
