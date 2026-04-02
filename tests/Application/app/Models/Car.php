<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\CarBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * Car model with a custom query builder via newEloquentBuilder() override (pre-Laravel 12 pattern).
 */
final class Car extends Model
{
    protected $table = 'cars';

    public function newEloquentBuilder($query): CarBuilder
    {
        return new CarBuilder($query);
    }
}
