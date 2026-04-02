<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property string $id
 * @property CarbonInterface|null $email_verified_at
 * @property Phone|null $phone This is nullable HasOne relationship to the Phone Model
 * @property non-empty-string $first_name_using_legacy_accessor
 */
class User extends Authenticatable
{
    use SoftDeletes;

    protected $table = 'users';

    /**
     * @psalm-return HasOne<Phone, $this>
     */
    public function phone(): HasOne
    {
        return $this->hasOne(Phone::class);
    }

    /**
     * @psalm-return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * @psalm-return HasManyThrough<Mechanic, Car, $this>
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
        return $this->morphOne(Image::class, 'imageable');
    }

    /**
     * Legacy scope: called as User::query()->active()
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeActive($query)
    {
        return $query->where('active', 1);
    }

    /** Modern scope using #[Scope] attribute (Laravel 12+): called as User::query()->verified() */
    #[Scope]
    public function verified(Builder $query): void
    {
        $query->whereNotNull('email_verified_at');
    }

    public function getFirstNameUsingLegacyAccessorAttribute(): string
    {
        return $this->name;
    }

    /** Legacy setter */
    public function setNicknameAttribute(string $value): void
    {
        $this->attributes['nickname'] = \strtolower($value);
    }

    /**
     * Modern Accessor, see https://laravel.com/docs/master/eloquent-mutators#accessors-and-mutators
     * @return Attribute<string, string>
     */
    protected function firstName(): Attribute
    {
        return Attribute::make(
            get: fn(mixed $value): string => \ucfirst((string) $value),
            set: fn(string $value): string => \strtolower($value),
        );
    }

    /**
     * Read-only Attribute — TSet is never, so writes should be rejected.
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(
            fn(mixed $value): string => 'computed',
        );
    }
}
