<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

final class Mechanic extends Model
{
    protected $table = 'mechanics';

    /**
     * @psalm-return HasOneThrough<User>
     */
    public function carOwner(): HasOneThrough
    {
        return $this->hasOneThrough(User::class, Car::class);
    }
}
