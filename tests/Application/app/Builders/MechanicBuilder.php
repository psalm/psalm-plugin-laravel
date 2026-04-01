<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Custom query builder for Mechanic model.
 *
 * Demonstrates the Laravel 13 pattern of custom builders via static $builder property.
 *
 * @template TModel of Model
 * @extends Builder<TModel>
 */
class MechanicBuilder extends Builder
{
    /**
     * @return self<TModel>
     */
    public function whereCertified(): self
    {
        return $this->where('certified', true);
    }
}
