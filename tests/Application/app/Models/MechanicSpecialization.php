<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PicksArchivedRevisions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Mechanic skill area (engine, transmission, electrical).
 *
 * Composes revision relations through a trait that resolves an `insteadof` itself (#1613).
 */
final class MechanicSpecialization extends Model
{
    use PicksArchivedRevisions;

    protected $table = 'mechanic_specializations';

    /**
     * @psalm-return BelongsToMany<Mechanic>
     */
    public function mechanics(): BelongsToMany
    {
        return $this->belongsToMany(Mechanic::class);
    }
}
