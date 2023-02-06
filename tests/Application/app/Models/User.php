<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property string $id
 * @property CarbonInterface|null $email_verified_at
 */
class User extends Authenticatable
{
    protected $table = 'users';

    /**
     * @psalm-return HasOne<Phone>
     */
    public function phone(): HasOne
    {
        return $this->hasOne(Phone::class);
    }

    /**
     * @psalm-return BelongsToMany<Role>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * @psalm-return HasManyThrough<Mechanic>
     */
    public function carsAtMechanic(): HasManyThrough
    {
        return $this->hasManyThrough(Mechanic::class, Car::class);
    }

    /**
     * Get the user's image.
     */
    public function image()
    {
        return $this->morphOne(Image::Class, 'imageable');
    }

    /**
     * Scope a query to only include active users.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('active', 1);
    }

    public function getFirstNameUsingLegacyAccessorAttribute(): string
    {
        return $this->name;
    }
}
