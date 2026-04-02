<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\MechanicBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * Mechanic model with a custom query builder via static $builder property (all Laravel versions).
 */
final class Mechanic extends Model
{
    protected $table = 'mechanics';

    /**
     * Legacy scope for testing scope resolution on the static $builder property pattern.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExperienced($query)
    {
        return $query->where('years_experience', '>', 5);
    }

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
