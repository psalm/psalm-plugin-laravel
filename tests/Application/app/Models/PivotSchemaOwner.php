<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

final class PivotSchemaOwner extends Model
{
    /** @return BelongsToMany<MechanicSpecialization, self, Pivot&object{role: string}, 'pivot'> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(MechanicSpecialization::class, table: 'pivot_schema_owner_user')
            ->withPivot('role')
            ->withTimestamps();
    }
}
