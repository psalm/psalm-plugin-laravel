<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Custom query builder for Car model.
 *
 * Demonstrates the pre-Laravel 12 pattern of custom builders via newEloquentBuilder() override.
 *
 * @template TModel of Model
 * @extends Builder<TModel>
 */
class CarBuilder extends Builder
{
    /**
     * @return self<TModel>
     */
    public function whereElectric(): self
    {
        return $this->where('fuel_type', 'electric');
    }
}
