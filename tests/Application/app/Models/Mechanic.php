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

    public const SPECIALIZATION_ACCESSOR = 'certification';

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

    /** A custom pivot types its own attributes: `withPivot()` refines it with the column's `@property` type (#1998). */
    public function certifiedSpecializations(): BelongsToMany
    {
        return $this->belongsToMany(MechanicSpecialization::class)->using(SpecializationPivot::class)->withPivot('certified_at');
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
     * Specializations with `->as()` but no `->using()` — verifies that when only
     * the accessor is captured, slot 3 is still filled with the per-relation
     * `Pivot` default. (The handler emits all 4 slots unconditionally for
     * pivot-aware relations; this test pins the captured-vs-default split.)
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
     * Delegated pivot relation (#1613): the inner `->using()` carries over, while this outer
     * `->as()` overrides the inner `'details'` accessor, matching runtime order.
     */
    public function specializationProfiles(): BelongsToMany
    {
        return $this->specializationsWithCustomAccessor()->as('profile');
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
     * `Pivot`. A handler that hard-coded `Pivot` for the missing-mutator fallback
     * would silently emit the wrong template here.
     *
     * @psalm-return MorphToMany<WorkOrder, $this, \Illuminate\Database\Eloquent\Relations\MorphPivot, 'meta'>
     */
    public function workOrderTagsAccessorOnly(): MorphToMany
    {
        return $this->morphedByMany(WorkOrder::class, 'taggable')->as('meta');
    }

    /** An outer dynamic `as()` replaces the inner literal at runtime, so the accessor is unknown (#2348). */
    public function specializationsDynamicAccessor(): BelongsToMany
    {
        return $this->belongsToMany(MechanicSpecialization::class)->as('inner')->as($this->getTable());
    }

    /** A class constant resolves to its literal accessor (#2348). */
    public function specializationsConstAccessor(): BelongsToMany
    {
        return $this->belongsToMany(MechanicSpecialization::class)->as(self::SPECIALIZATION_ACCESSOR);
    }

    /** Untyped relation: related model, pivot columns and accessor come from the body alone (#2348). */
    public function specializationNotes()
    {
        return $this->belongsToMany(MechanicSpecialization::class)->withPivot('notes')->as('note');
    }

    /** @psalm-return BelongsToMany<MechanicSpecialization, $this, \Illuminate\Database\Eloquent\Relations\Pivot, 'details'>|null */
    public function nullableSpecializations(): ?BelongsToMany
    {
        if (!$this->exists) {
            return null;
        }

        return $this->belongsToMany(MechanicSpecialization::class)->as('details');
    }

    /** Returns that differ only in the accessor leave it unknown and keep the related model and pivot columns (#2348). */
    public function specializationsBranchAccessor(): BelongsToMany
    {
        if ($this->exists) {
            return $this->belongsToMany(MechanicSpecialization::class)->withPivot('notes')->as($this->getTable());
        }

        return $this->belongsToMany(MechanicSpecialization::class)->withPivot('notes');
    }

    /** An omitted `as()` and `as('pivot')` hydrate the same key (#2348). */
    public function specializationsBranchExplicitPivot(): BelongsToMany
    {
        if ($this->exists) {
            return $this->belongsToMany(MechanicSpecialization::class)->as('pivot');
        }

        return $this->belongsToMany(MechanicSpecialization::class);
    }

    /**
     * A dynamic outer `as()` on a delegated relation: the accessor is unknown, the delegate's pivot stays (#2348).
     * The omitted TAccessor slot is no accessor promise.
     *
     * @psalm-return BelongsToMany<MechanicSpecialization, $this, SpecializationPivot>
     */
    public function specializationsDelegatedDynamic(): BelongsToMany
    {
        return $this->specializationsWithPivot()->as($this->getTable());
    }

    /**
     * A declared literal accessor stands in for one the body does not show (#2348).
     *
     * @psalm-return BelongsToMany<MechanicSpecialization, $this, \Illuminate\Database\Eloquent\Relations\Pivot, 'declared'>
     */
    public function specializationsDeclaredAccessor(): BelongsToMany
    {
        return $this->belongsToMany(MechanicSpecialization::class)->as('decl' . 'ared');
    }

    /** A scope and a Builder macro run on the query, not the relation, so the accessor stays (#2348). */
    public function workOrderTagsScoped(): MorphToMany
    {
        return $this->morphedByMany(WorkOrder::class, 'taggable')->as('meta')->urgent()->withTrashed();
    }

    /** A registered relation macro (tests/Type/macro-fixtures.php) replaces the accessor the parser read (#2348). */
    public function specializationsAccessorMacro(): BelongsToMany
    {
        return $this->belongsToMany(MechanicSpecialization::class)->as('first')->rewriteAccessor();
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
