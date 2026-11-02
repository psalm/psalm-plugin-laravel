--FILE--
<?php declare(strict_types=1);

use App\Models\Part;
use App\Models\Shop;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * `BelongsToMany` / `MorphToMany` declare `TPivotModel` and `TAccessor` with defaults, so a docblock that
 * names only `<TRelatedModel, TDeclaringModel>` is complete: no MissingTemplateParam, and the trailing slots
 * fill from the defaults (`Pivot` / `MorphPivot`, `'pivot'`). `HasRelationships::belongsToMany()` therefore
 * returns the 2-arg form.
 */
final class TwoArgPivotOwner extends Model
{
    /** @psalm-return BelongsToMany<Part, $this> */
    public function viaThis(): BelongsToMany
    {
        return $this->belongsToMany(Part::class);
    }

    /** @psalm-return BelongsToMany<Part, self> */
    public function viaSelf(): BelongsToMany
    {
        $_inBody = $this->belongsToMany(Part::class);
        /** @psalm-check-type-exact $_inBody = BelongsToMany<Part, TwoArgPivotOwner, \Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'> */

        return $_inBody;
    }

    /** @psalm-return MorphToMany<WorkOrder, $this> */
    public function morphed(): MorphToMany
    {
        return $this->morphToMany(WorkOrder::class, 'workable');
    }

    /** The stub's 2-arg `morphToMany()` / `morphedByMany()` returns fill `MorphPivot`, not `Pivot`. */
    public function morphPivotsFromTheStubDefaults(): void
    {
        $_toMany = $this->morphToMany(WorkOrder::class, 'workable')->first()?->pivot;
        /** @psalm-check-type-exact $_toMany = \Illuminate\Database\Eloquent\Relations\MorphPivot|null */

        $_byMany = $this->morphedByMany(WorkOrder::class, 'workable')->first()?->pivot;
        /** @psalm-check-type-exact $_byMany = \Illuminate\Database\Eloquent\Relations\MorphPivot|null */
    }
}

function two_arg_docblock_call_site(TwoArgPivotOwner $owner): void
{
    $_relation = $owner->viaThis();
    /** @psalm-check-type-exact $_relation = BelongsToMany<Part, TwoArgPivotOwner, \Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'> */

    $_pivot = $owner->viaThis()->first()?->pivot;
    /** @psalm-check-type-exact $_pivot = \Illuminate\Database\Eloquent\Relations\Pivot|null */
}

/** @param BelongsToMany<Part, Shop> $relation */
function two_arg_docblock_param(BelongsToMany $relation): void
{
    $_first = $relation->first();
    /** @psalm-check-type-exact $_first = (Part&object{pivot: \Illuminate\Database\Eloquent\Relations\Pivot})|null */

    $_pivot = $_first?->pivot;
    /** @psalm-check-type-exact $_pivot = \Illuminate\Database\Eloquent\Relations\Pivot|null */

    $_chained = $relation->wherePivot('quantity', 1)->first()?->pivot;
    /** @psalm-check-type-exact $_chained = \Illuminate\Database\Eloquent\Relations\Pivot|null */
}

/** @param MorphToMany<WorkOrder, Shop> $relation */
function two_arg_docblock_morph_param(MorphToMany $relation): void
{
    $_pivot = $relation->first()?->pivot;
    /** @psalm-check-type-exact $_pivot = \Illuminate\Database\Eloquent\Relations\MorphPivot|null */
}

// Negative control: `TDeclaringModel` has no default, so a 1-arg docblock is still incomplete.
/** @param BelongsToMany<Part> $relation */
function one_arg_docblock_param(BelongsToMany $relation): int
{
    return $relation->count();
}
?>
--EXPECTF--
MissingTemplateParam on line %d: Illuminate\Database\Eloquent\Relations\BelongsToMany has missing template params, expecting at least 2
