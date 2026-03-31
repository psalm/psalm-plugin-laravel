<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Custom query builder for Post model.
 *
 * Demonstrates the Laravel 12 pattern of dedicated query builders,
 * used with #[UseEloquentBuilder] attribute on the model.
 *
 * @template TModel of Model
 * @extends Builder<TModel>
 */
class PostBuilder extends Builder
{
    /**
     * @return self<TModel>
     */
    public function wherePublished(): self
    {
        return $this->whereNotNull('published_at');
    }

    /**
     * @return self<TModel>
     */
    public function whereDraft(): self
    {
        return $this->whereNull('published_at');
    }
}
