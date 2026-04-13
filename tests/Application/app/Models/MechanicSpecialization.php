<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Mechanic skill area (engine, transmission, electrical).
 */
final class MechanicSpecialization extends Model
{
    protected $table = 'mechanic_specializations';

    /**
     * @psalm-return BelongsToMany<Mechanic>
     */
    public function mechanics(): BelongsToMany
    {
        return $this->belongsToMany(Mechanic::class);
    }
}
