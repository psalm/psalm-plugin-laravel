<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
    public function whereCompleted(): self
    {
        return $this->where('status', 'completed');
    }

    public function wherePending(): self
    {
        return $this->where('status', 'pending');
    }

    /**
     * Custom method with parameters — exercises the getMethodParams provider path.
     */
    public function whereByMechanic(int $mechanicId): self
    {
        return $this->where('mechanic_id', $mechanicId);
    }

    /**
     * Attaches a computed `favorite` column to fetched rows via addSelect().
     *
     * Reproduces the dynamic-attribute pattern from issue #805: a builder method adds a
     * column that is not a real schema column, accessor, or relation, then consumers read
     * $model->favorite. The plugin does not track such additions, so the read is a
     * false-positive UndefinedMagicPropertyFetch.
     *
     * @see https://github.com/psalm/psalm-plugin-laravel/issues/805
     */
    public function withFavoriteStatus(int $userId): self
    {
        $this->addSelect([
            'favorite' => DB::table('favorites')
                ->selectRaw('1')
                ->where('favorites.user_id', $userId),
        ]);

        return $this;
    }
}
