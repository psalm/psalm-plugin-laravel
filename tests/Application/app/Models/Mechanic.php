<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\MechanicBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Technician who performs repairs.
 *
 * Custom query builder via static $builder property (all Laravel versions).
 */
final class Mechanic extends Model
{
    protected $table = 'mechanics';

    /** @var class-string<MechanicBuilder<static>> */
    protected static string $builder = MechanicBuilder::class;

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    /**
     * @psalm-return HasOneThrough<Customer>
     */
    public function vehicleOwner(): HasOneThrough
    {
        return $this->hasOneThrough(Customer::class, Vehicle::class);
    }

    /**
     * @psalm-return BelongsToMany<MechanicSpecialization, $this>
     */
    public function specializations(): BelongsToMany
    {
        return $this->belongsToMany(MechanicSpecialization::class);
    }

    /**
     * Specializations with a custom pivot model — used to test 4-template BelongsToMany.
     *
     * @psalm-return BelongsToMany<MechanicSpecialization, $this, SpecializationPivot, 'pivot'>
     */
    public function specializationsWithPivot(): BelongsToMany
    {
        return $this->belongsToMany(MechanicSpecialization::class)->using(SpecializationPivot::class);
    }

    /**
     * Admin bookmarks for this mechanic (inverse of Admin::mechanics()).
     *
     * @psalm-return MorphToMany<Admin>
     */
    public function bookmarkedAdmins(): MorphToMany
    {
        return $this->morphedByMany(Admin::class, 'bookmarkable');
    }

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
}
