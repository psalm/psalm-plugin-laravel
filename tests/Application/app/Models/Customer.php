<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Car owner who brings vehicles to the repair shop.
 *
 * Authenticatable model with all accessor/mutator patterns and scopes.
 *
 * @property string $id
 * @property CarbonInterface|null $email_verified_at
 * @property Vehicle|null $primary_vehicle Nullable HasOne relationship to the Vehicle Model
 * @property non-empty-string $first_name_using_legacy_accessor
 * @property int<0, max> $vehicles_count Declared to verify @property takes precedence over aggregate type inference
 */
class Customer extends Authenticatable
{
    use SoftDeletes;

    protected $table = 'customers';

    /**
     * The customer's primary vehicle (first registered).
     *
     * @psalm-return HasOne<Vehicle, $this>
     */
    public function primaryVehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class);
    }

    /**
     * Latest acquired vehicle (HasOneOfMany).
     *
     * @psalm-return HasOne<Vehicle, $this>
     */
    public function latestVehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class)->latestOfMany('acquired_at');
    }

    /**
     * All vehicles belonging to this customer.
     *
     * @psalm-return HasMany<Vehicle, $this>
     */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /**
     * All work orders for this customer's vehicles.
     *
     * @psalm-return HasManyThrough<WorkOrder, Vehicle, $this>
     */
    public function workOrders(): HasManyThrough
    {
        return $this->hasManyThrough(WorkOrder::class, Vehicle::class);
    }

    /**
     * Admin bookmarks for this customer (inverse of Admin::customers()).
     *
     * @psalm-return MorphToMany<Admin>
     */
    public function bookmarkedAdmins(): MorphToMany
    {
        return $this->morphedByMany(Admin::class, 'bookmarkable');
    }

    /**
     * No declared return type — exercises the AST body inference path.
     */
    public function latestReport()
    {
        return $this->morphOne(DamageReport::class, 'reportable');
    }

    /**
     * Legacy scope: called as Customer::query()->active().
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive($query)
    {
        return $query->where('active', 1);
    }

    /**
     * Modern scope using #[Scope] attribute (Laravel 12+): called as Customer::query()->verified().
     */
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
     *
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
     *
     * @return Attribute<string, never>
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(
            fn(mixed $value): string => 'computed',
        );
    }
}
