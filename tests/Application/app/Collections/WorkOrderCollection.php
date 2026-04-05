<?php

declare(strict_types=1);

namespace App\Collections;

use Illuminate\Database\Eloquent\Collection;

/**
 * Custom collection for WorkOrder models — tests #[CollectedBy] on Relation method calls.
 *
 * @template TKey of array-key
 * @template TModel
 * @extends Collection<TKey, TModel>
 */
class WorkOrderCollection extends Collection
{
    /**
     * Sum the total labor hours across all work orders.
     */
    public function totalLaborHours(): float
    {
        return $this->sum(fn(\App\Models\WorkOrder $wo): float => (float) $wo->getAttribute('labor_hours'));
    }
}
