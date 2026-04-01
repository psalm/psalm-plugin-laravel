<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\MechanicBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * Mechanic model with a custom query builder via static $builder property (Laravel 13+).
 */
final class Mechanic extends Model
{
    protected $table = 'mechanics';

    /** @var class-string<MechanicBuilder<static>> */
    protected static string $builder = MechanicBuilder::class;

    /**
     * @psalm-return HasOneThrough<User>
     */
    public function carOwner(): HasOneThrough
    {
        return $this->hasOneThrough(User::class, Car::class);
    }
}
