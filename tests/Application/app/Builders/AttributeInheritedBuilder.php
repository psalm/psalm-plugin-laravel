<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Custom builder attached through #[UseEloquentBuilder] on an abstract base model, which concrete
 * descendants inherit at runtime.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2109
 *
 * @template TModel of Model
 * @extends Builder<TModel>
 */
class AttributeInheritedBuilder extends Builder
{
    /** @psalm-return self<TModel> */
    public function whereArchived(): self
    {
        return $this->where('archived', true);
    }
}
