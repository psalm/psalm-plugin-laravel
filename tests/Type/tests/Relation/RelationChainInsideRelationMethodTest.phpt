--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Regression test for chaining Builder-mixin methods (declared on Builder with @return $this)
 * directly onto a relation factory call inside a relation method.
 *
 * The bug: `$this->belongsTo(X)->withoutGlobalScopes()` raised MixedMethodCall because the
 * `$this` template parameter in the HasRelationships stubs' `@return Relation<TRelated, $this>`
 * was not substituted with the late-static-bound class. The intermediate relation collapsed
 * to mixed, so the chained Builder-mixin call had nothing to dispatch on.
 *
 * Fixed by switching the stubs to `@return Relation<TRelated, static>`. Psalm 7 substitutes
 * `static` correctly in template position.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/913
 */
final class Issue913Customer extends Model
{
    protected $table = 'issue913_customers';
}

final class Issue913AbandonedCart extends Model
{
    protected $table = 'issue913_abandoned_carts';

    /**
     * Confirms the intermediate factory call resolves to a concrete generic relation type,
     * not mixed. Without this guard a future regression that re-collapses the intermediate
     * to mixed would still pass the surrounding return-type check (mixed satisfies any
     * declared return), so we assert the inferred type directly.
     */
    public function inspectIntermediateType(): void
    {
        $_ = $this->belongsTo(Issue913Customer::class);
        /** @psalm-check-type-exact $_ = BelongsTo<Issue913Customer, Issue913AbandonedCart> */
    }

    /** @return BelongsTo<Issue913Customer, self> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Issue913Customer::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Issue913Customer, self> */
    public function customerWithSpecificScope(): BelongsTo
    {
        return $this->belongsTo(Issue913Customer::class)->withoutGlobalScope('soft_deleting');
    }

    /** @return HasOne<Issue913Customer, self> */
    public function primaryCustomer(): HasOne
    {
        return $this->hasOne(Issue913Customer::class)->withoutGlobalScopes();
    }

    /** @return HasMany<Issue913Customer, self> */
    public function relatedCustomers(): HasMany
    {
        return $this->hasMany(Issue913Customer::class)->withoutGlobalScopes();
    }

    /**
     * Pivot-bearing relation: BelongsToMany has the 4-template shape
     * `<TRelatedModel, TDeclaringModel, TPivotModel, TAccessor>`. The fix must
     * propagate `static` into the second slot without disturbing the trailing
     * defaults filled by ModelRelationReturnTypeHandler.
     */
    public function inspectBelongsToManyIntermediateType(): void
    {
        $_ = $this->belongsToMany(Issue913Customer::class);
        /** @psalm-check-type-exact $_ = BelongsToMany<Issue913Customer, Issue913AbandonedCart, Pivot, 'pivot'> */
    }

    /** @return BelongsToMany<Issue913Customer, self, Pivot, 'pivot'> */
    public function customersViaPivot(): BelongsToMany
    {
        return $this->belongsToMany(Issue913Customer::class)->withoutGlobalScopes();
    }

    /**
     * Through relation: HasManyThrough has the 3-template shape
     * `<TRelatedModel, TIntermediateModel, TDeclaringModel>`. Verifies `static`
     * lands in the trailing slot rather than the middle one.
     */
    public function inspectHasManyThroughIntermediateType(): void
    {
        $_ = $this->hasManyThrough(Issue913Customer::class, Issue913IntermediateModel::class);
        /** @psalm-check-type-exact $_ = HasManyThrough<Issue913Customer, Issue913IntermediateModel, Issue913AbandonedCart> */
    }

    /** @return HasManyThrough<Issue913Customer, Issue913IntermediateModel, self> */
    public function customersViaIntermediate(): HasManyThrough
    {
        return $this->hasManyThrough(Issue913Customer::class, Issue913IntermediateModel::class)->withoutGlobalScopes();
    }

    /**
     * morphTo has no TRelatedModel template (defaults to Model in the stub).
     * Verifies `static` substitutes in the TDeclaringModel slot independently
     * of the related-type slot shape.
     */
    public function inspectMorphToIntermediateType(): void
    {
        $_ = $this->morphTo();
        /** @psalm-check-type-exact $_ = MorphTo<Model, Issue913AbandonedCart> */
    }

    /** @return MorphTo<Model, self> */
    public function reportable(): MorphTo
    {
        return $this->morphTo()->withoutGlobalScopes();
    }
}

final class Issue913IntermediateModel extends Model
{
    protected $table = 'issue913_intermediates';
}
?>
--EXPECTF--
