<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Issue1944Owner extends Model
{
    public function related(): BelongsToMany
    {
        return $this->belongsToMany(Issue1944Related::class, table: 'owner_related')
            ->withPivot('role');
    }

    public function dynamicPivot(): BelongsToMany
    {
        $column = 'role';

        return $this->belongsToMany(Issue1944Related::class, table: 'owner_related')
            ->withPivot($column);
    }

    public function customPivot(): BelongsToMany
    {
        return $this->belongsToMany(Issue1944Related::class, table: 'owner_related')
            ->using(Issue1944Pivot::class)
            ->withPivot('role');
    }

    public function customAccessor(): BelongsToMany
    {
        return $this->belongsToMany(Issue1944Related::class, table: 'owner_related')
            ->as('membership')
            ->withPivot('role');
    }
}
