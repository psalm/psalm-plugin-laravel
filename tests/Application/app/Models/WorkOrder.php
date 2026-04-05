<?php

declare(strict_types=1);

namespace App\Models;

use App\Collections\WorkOrderCollection;
use Illuminate\Database\Eloquent\Attributes\CollectedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A single repair visit for a vehicle with related damage reports.
 *
 * Uses #[CollectedBy] to test custom collection on Relation method calls (#658).
 */
#[CollectedBy(WorkOrderCollection::class)]
class WorkOrder extends Model
{
    protected $table = 'work_orders';

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function damageReports(): MorphMany
    {
        return $this->morphMany(DamageReport::class, 'reportable');
    }
}
