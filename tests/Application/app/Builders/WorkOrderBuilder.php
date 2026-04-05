<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Custom query builder for WorkOrder model.
 *
 * Demonstrates the Laravel 12 pattern of dedicated query builders,
 * used with #[UseEloquentBuilder] attribute on the model.
 *
 * @template TModel of Model
 * @extends Builder<TModel>
 */
class WorkOrderBuilder extends Builder
{
    /**
     * @return self<TModel>
     */
    public function whereCompleted(): self
    {
        return $this->where('status', 'completed');
    }

    /**
     * @return self<TModel>
     */
    public function wherePending(): self
    {
        return $this->where('status', 'pending');
    }

    /**
     * Custom method with parameters — exercises the getMethodParams provider path.
     *
     * @return self<TModel>
     */
    public function whereByMechanic(int $mechanicId): self
    {
        return $this->where('mechanic_id', $mechanicId);
    }
}
