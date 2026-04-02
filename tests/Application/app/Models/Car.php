<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\CarBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Car model with a custom query builder via newEloquentBuilder() override (pre-Laravel 12 pattern).
 */
final class Car extends Model
{
    protected $table = 'cars';

    /**
     * Legacy scope for testing scope resolution on the newEloquentBuilder() pattern.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAvailable($query)
    {
        return $query->where('available', true);
    }

    public function newEloquentBuilder($query): CarBuilder
    {
        return new CarBuilder($query);
    }
}
