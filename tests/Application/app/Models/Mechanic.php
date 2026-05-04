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
     * Specializations with both a custom pivot model and a custom accessor name —
     * exercises the chain capture for `->as('alias')` in addition to `->using()`.
     *
     * @psalm-return BelongsToMany<MechanicSpecialization, $this, SpecializationPivot, 'details'>
     */
    public function specializationsWithCustomAccessor(): BelongsToMany
    {
        return $this->belongsToMany(MechanicSpecialization::class)
            ->using(SpecializationPivot::class)
            ->as('details');
    }

    /**
     * Specializations with `->as()` but no `->using()` — exercises the all-or-nothing
     * emission rule: when only one mutator is captured, the handler still emits both
     * pivot and accessor slots (filling the missing one with its declared default).
     *
     * @psalm-return BelongsToMany<MechanicSpecialization, $this, \Illuminate\Database\Eloquent\Relations\Pivot, 'details'>
     */
    public function specializationsAccessorOnly(): BelongsToMany
    {
        return $this->belongsToMany(MechanicSpecialization::class)->as('details');
    }

    /**
     * Specializations with `->as()` then `->using()` — exercises order-independence
     * of the chain-capture recursion (the outside-in walk should record both
     * mutators regardless of their source-order).
     *
     * @psalm-return BelongsToMany<MechanicSpecialization, $this, SpecializationPivot, 'details'>
     */
    public function specializationsAsThenUsing(): BelongsToMany
    {
        return $this->belongsToMany(MechanicSpecialization::class)
            ->as('details')
            ->using(SpecializationPivot::class);
    }

    /**
     * Tagging WorkOrders polymorphically with a custom pivot — used to test that the
     * chain-capture also fires for MorphToMany (not just BelongsToMany).
     *
     * @psalm-return MorphToMany<WorkOrder, $this, SpecializationPivot, 'pivot'>
     */
    public function workOrderTagsWithPivot(): MorphToMany
    {
        return $this->morphedByMany(WorkOrder::class, 'taggable')->using(SpecializationPivot::class);
    }

    /**
     * Tagging WorkOrders with `->as()` and no `->using()` — exercises the per-relation
     * pivot default selection: MorphToMany's TPivotModel default is `MorphPivot`, not
     * `Pivot`. A handler that hard-coded `Pivot` for the all-or-nothing fallback would
     * silently emit the wrong template here.
     *
     * @psalm-return MorphToMany<WorkOrder, $this, \Illuminate\Database\Eloquent\Relations\MorphPivot, 'meta'>
     */
    public function workOrderTagsAccessorOnly(): MorphToMany
    {
        return $this->morphedByMany(WorkOrder::class, 'taggable')->as('meta');
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
