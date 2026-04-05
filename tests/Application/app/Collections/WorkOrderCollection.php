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
     * Get only completed work orders.
     *
     * Returns static to test that custom collection methods with
     * static return type preserve the collection's generic parameters.
     *
     * @psalm-return static
     */
    public function completed(): static
    {
        return $this->filter(fn(\App\Models\WorkOrder $wo): bool => (bool) $wo->getAttribute('completed'));
    }

    /**
     * Sum the total labor hours across all work orders.
     */
    public function totalLaborHours(): float
    {
        return $this->sum(fn(\App\Models\WorkOrder $wo): float => (float) $wo->getAttribute('labor_hours'));
    }
}
