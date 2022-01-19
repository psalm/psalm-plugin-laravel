<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Models;

use Illuminate\Database\Eloquent\Model;

final class Car extends Model
{
    protected $table = 'cars';
};
