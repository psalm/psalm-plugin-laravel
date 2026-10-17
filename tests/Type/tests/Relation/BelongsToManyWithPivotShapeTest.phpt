--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox\PivotShape;

use App\Models\Part;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * #1944: `withPivot()` / `withTimestamps()` refine the pivot slot of the relation inside a relation method
 * body. The type-test harness runs no migrations, so a column without a custom pivot class is `mixed`;
 * the schema-typed columns are pinned by BelongsToManyPivotShapeSchemaEmissionTest. Timestamps follow the
 * base pivot's casts: the PARENT model's created-at / updated-at columns are date-cast, and only when the
 * created-at one is selected.
 *
 * @property int $quantity
 */
class Membership extends Pivot
{
}

class Owner extends Model
{
    public function variadic(): void
    {
        $r = $this->belongsToMany(Part::class)->withPivot('a', 'b');
        /** @psalm-trace $r */
        echo \get_debug_type($r);
    }

    public function arrayForm(): void
    {
        // Laravel reads only the first argument when it is an array.
        $r = $this->belongsToMany(Part::class)->withPivot(['a', 'b'], 'ignored');
        /** @psalm-trace $r */
        echo \get_debug_type($r);
    }

    public function mergedAcrossCalls(): void
    {
        $r = $this->belongsToMany(Part::class)->withPivot('a')->withTimestamps()->where('x', 1)->withPivot('b', 'a');
        /** @psalm-trace $r */
        echo \get_debug_type($r);
    }

    public function nonLiteralArgumentsAreSkipped(string $dynamic): void
    {
        $r = $this->belongsToMany(Part::class)->withPivot('a', $dynamic);
        /** @psalm-trace $r */
        echo \get_debug_type($r);

        $none = $this->belongsToMany(Part::class)->withPivot($dynamic);
        /** @psalm-trace $none */
        echo \get_debug_type($none);
    }

    public function timestampsDefault(): void
    {
        $r = $this->belongsToMany(Part::class)->withTimestamps();
        /** @psalm-trace $r */
        echo \get_debug_type($r);
    }

    public function timestampsRenamedCreatedAt(): void
    {
        // Timestamps stay off when the parent's created-at column is not selected: nothing is date-cast.
        $r = $this->belongsToMany(Part::class)->withTimestamps('added_at');
        /** @psalm-trace $r */
        echo \get_debug_type($r);
    }

    public function timestampsRenamedUpdatedAt(): void
    {
        $r = $this->belongsToMany(Part::class)->withTimestamps(updatedAt: 'changed_at');
        /** @psalm-trace $r */
        echo \get_debug_type($r);
    }

    public function timestampsDisabledColumn(): void
    {
        // `false` (Laravel 13) drops the column, and the updated-at one casts only with the created-at one.
        $r = $this->belongsToMany(Part::class)->withTimestamps(false);
        /** @psalm-trace $r */
        echo \get_debug_type($r);
    }

    public function createdAtSelectedByWithPivot(): void
    {
        // The cast follows the selected columns, not the withTimestamps() call.
        $r = $this->belongsToMany(Part::class)->withPivot('created_at');
        /** @psalm-trace $r */
        echo \get_debug_type($r);
    }

    public function updatedAtAloneIsPlain(): void
    {
        $r = $this->belongsToMany(Part::class)->withPivot('updated_at');
        /** @psalm-trace $r */
        echo \get_debug_type($r);
    }

    public function updatedAtCastsOnceCreatedAtJoins(): void
    {
        $r = $this->belongsToMany(Part::class)->withPivot('updated_at')->withPivot('created_at');
        /** @psalm-trace $r */
        echo \get_debug_type($r);
    }

    public function customPivotClassWins(): void
    {
        // The class describes its own columns (`@property` / casts / schema); the rest stays mixed.
        $r = $this->belongsToMany(Part::class)->using(Membership::class)->withPivot('quantity', 'note');
        /** @psalm-trace $r */
        echo \get_debug_type($r);
    }

    public function customPivotClassDoesNotCastTimestamps(): void
    {
        $r = $this->belongsToMany(Part::class)->using(Membership::class)->withTimestamps();
        /** @psalm-trace $r */
        echo \get_debug_type($r);
    }

    public function usingAfterWithPivotResetsTheShape(): void
    {
        // A custom pivot class describes its own columns, so using() drops the refinement.
        $r = $this->belongsToMany(Part::class)->withPivot('a')->using(Membership::class);
        /** @psalm-trace $r */
        echo \get_debug_type($r);
    }

    public function accessorIsKept(): void
    {
        $r = $this->belongsToMany(Part::class)->as('membership')->withPivot('a');
        /** @psalm-trace $r */
        echo \get_debug_type($r);
    }

    public function morphRelations(): void
    {
        $r = $this->morphToMany(Part::class, 'taggable')->withPivot('a')->withTimestamps();
        /** @psalm-trace $r */
        echo \get_debug_type($r);

        $inverse = $this->morphedByMany(Part::class, 'taggable')->withPivot('a');
        /** @psalm-trace $inverse */
        echo \get_debug_type($inverse);
    }

    /** @return BelongsToMany<Part, self, Pivot&object{a: mixed, b: mixed}, 'pivot'> */
    public function declaredShapeMatchesInferredOne(): BelongsToMany
    {
        return $this->belongsToMany(Part::class)->withPivot('a', 'b')->wherePivot('a', 1);
    }

    /** @return BelongsToMany<Part, self, Pivot, 'pivot'> */
    public function declaredPlainPivotStillAccepted(): BelongsToMany
    {
        return $this->belongsToMany(Part::class)->withPivot('a')->withTimestamps();
    }

    /** @return MorphToMany<Part, self, MorphPivot, 'pivot'> */
    public function declaredMorphPivotStillAccepted(): MorphToMany
    {
        return $this->morphToMany(Part::class, 'taggable')->withPivot('a')->withTimestamps();
    }
}

// The parent's own timestamp column names decide what the pivot date-casts (the untyped constants are
// reported by Psalm; typed class constants need PHP 8.3).
class RenamedTimestamps extends Model
{
    public const CREATED_AT = 'added';

    public const UPDATED_AT = null;

    public function parts(): void
    {
        $r = $this->belongsToMany(Part::class)->withTimestamps();
        /** @psalm-trace $r */
        echo \get_debug_type($r);
    }
}

class OverriddenTimestampColumn extends Model
{
    #[\Override]
    public function getCreatedAtColumn(): string
    {
        return 'made';
    }

    public function parts(): void
    {
        // An overridden column accessor makes the default names unprovable: no timestamp column is claimed.
        $r = $this->belongsToMany(Part::class)->withTimestamps()->withPivot('a');
        /** @psalm-trace $r */
        echo \get_debug_type($r);
    }
}

// A userland subclass may reshape the templates: its receivers keep the stub's `$this`.
/** @extends BelongsToMany<Part, Owner, Pivot, 'pivot'> */
final class CustomRelation extends BelongsToMany
{
}

function userland_subclass_declines(CustomRelation $relation): void
{
    $r = $relation->withPivot('a');
    /** @psalm-trace $r */
    echo \get_debug_type($r);
}

/**
 * @param BelongsToMany<Part, Owner, Pivot, 'pivot'>|MorphToMany<Part, Owner, MorphPivot, 'pivot'> $relation
 */
function union_receiver_declines(BelongsToMany|MorphToMany $relation): void
{
    $r = $relation->withPivot('a');
    /** @psalm-trace $r */
    echo \get_debug_type($r);
}

/**
 * @template T of BelongsToMany<Part, Owner, Pivot, 'pivot'>
 * @param T $relation
 */
function template_receiver_declines(BelongsToMany $relation): void
{
    $r = $relation->withPivot('a');
    /** @psalm-trace $r */
    echo \get_debug_type($r);
}

// A receiver held in a variable splits the chain from its factory call: the table is unknown, the columns stay.
/** @param BelongsToMany<Part, Owner, Pivot, 'pivot'> $relation */
function receiver_from_a_parameter(BelongsToMany $relation): void
{
    $r = $relation->withPivot('a')->withTimestamps();
    /** @psalm-trace $r */
    echo \get_debug_type($r);
}

// A declared shape of the receiver is kept and extended.
/** @param BelongsToMany<Part, Owner, Pivot&object{role: string}, 'pivot'> $relation */
function declared_shape_is_extended(BelongsToMany $relation): void
{
    $r = $relation->withPivot('a');
    /** @psalm-trace $r */
    echo \get_debug_type($r);
}

// A generic userland subclass may reshape the templates: only the two base classes are answered.
/**
 * @template TModel of Part
 * @template TParent of Owner
 * @extends BelongsToMany<TModel, TParent, Pivot, 'pivot'>
 */
final class TypedRelation extends BelongsToMany
{
}

/** @param TypedRelation<Part, Owner> $relation */
function generic_userland_subclass_declines(TypedRelation $relation): void
{
    $r = $relation->withPivot('a');
    /** @psalm-trace $r */
    echo \get_debug_type($r);
}

// A `@mixin` proxy dispatches to the relation's method, but its receiver is not the relation.
/** @mixin BelongsToMany<Part, Owner, Pivot, 'pivot'> */
final class RelationProxy
{
}

function mixin_proxy_declines(RelationProxy $proxy): void
{
    $r = $proxy->withPivot('a');
    /** @psalm-trace $r */
    echo \get_debug_type($r);
}

/** @param BelongsToMany<Part, Owner, Pivot, 'pivot'>&\Countable $relation */
function intersection_receiver_declines(BelongsToMany $relation): void
{
    $r = $relation->withPivot('a');
    /** @psalm-trace $r */
    echo \get_debug_type($r);
}
?>
--EXPECTF--
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner&static, Illuminate\Database\Eloquent\Relations\Pivot&object{a:mixed, b:mixed}, 'pivot'>
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner&static, Illuminate\Database\Eloquent\Relations\Pivot&object{a:mixed, b:mixed}, 'pivot'>
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner&static, Illuminate\Database\Eloquent\Relations\Pivot&object{a:mixed, created_at:Illuminate\Support\Carbon|null, updated_at:Illuminate\Support\Carbon|null, b:mixed}, 'pivot'>
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner&static, Illuminate\Database\Eloquent\Relations\Pivot&object{a:mixed}, 'pivot'>
Trace on line %d: $none: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner&static, Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>&static
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner&static, Illuminate\Database\Eloquent\Relations\Pivot&object{created_at:Illuminate\Support\Carbon|null, updated_at:Illuminate\Support\Carbon|null}, 'pivot'>
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner&static, Illuminate\Database\Eloquent\Relations\Pivot&object{added_at:mixed, updated_at:mixed}, 'pivot'>
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner&static, Illuminate\Database\Eloquent\Relations\Pivot&object{created_at:Illuminate\Support\Carbon|null, changed_at:mixed}, 'pivot'>
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner&static, Illuminate\Database\Eloquent\Relations\Pivot&object{updated_at:mixed}, 'pivot'>
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner&static, Illuminate\Database\Eloquent\Relations\Pivot&object{created_at:Illuminate\Support\Carbon|null}, 'pivot'>
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner&static, Illuminate\Database\Eloquent\Relations\Pivot&object{updated_at:mixed}, 'pivot'>
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner&static, Illuminate\Database\Eloquent\Relations\Pivot&object{updated_at:Illuminate\Support\Carbon|null, created_at:Illuminate\Support\Carbon|null}, 'pivot'>
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner&static, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Membership&object{quantity:int, note:mixed}, 'pivot'>&static
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner&static, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Membership&object{created_at:mixed, updated_at:mixed}, 'pivot'>&static
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner&static, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Membership, 'pivot'>&static
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner&static, Illuminate\Database\Eloquent\Relations\Pivot&object{a:mixed}, 'membership'>&static
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\MorphToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner&static, Illuminate\Database\Eloquent\Relations\MorphPivot&object{a:mixed, created_at:Illuminate\Support\Carbon|null, updated_at:Illuminate\Support\Carbon|null}, 'pivot'>
Trace on line %d: $inverse: Illuminate\Database\Eloquent\Relations\MorphToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner&static, Illuminate\Database\Eloquent\Relations\MorphPivot&object{a:mixed}, 'pivot'>
MissingClassConstType on line %d: Class constant "Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\RenamedTimestamps::CREATED_AT" should have a declared type.
MissingClassConstType on line %d: Class constant "Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\RenamedTimestamps::UPDATED_AT" should have a declared type.
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\RenamedTimestamps&static, Illuminate\Database\Eloquent\Relations\Pivot&object{added:Illuminate\Support\Carbon|null, updated_at:mixed}, 'pivot'>
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\OverriddenTimestampColumn&static, Illuminate\Database\Eloquent\Relations\Pivot&object{a:mixed}, 'pivot'>&static
Trace on line %d: $r: Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\CustomRelation
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner, Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>&static|Illuminate\Database\Eloquent\Relations\MorphToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner, Illuminate\Database\Eloquent\Relations\MorphPivot, 'pivot'>&static
Trace on line %d: $r: T:fn-tests\psalm\laravelplugin\sandbox\pivotshape\template_receiver_declines as Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner, Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner, Illuminate\Database\Eloquent\Relations\Pivot&object{a:mixed, created_at:Illuminate\Support\Carbon|null, updated_at:Illuminate\Support\Carbon|null}, 'pivot'>
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner, Illuminate\Database\Eloquent\Relations\Pivot&object{role:string, a:mixed}, 'pivot'>
Trace on line %d: $r: Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\TypedRelation<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner>
Trace on line %d: $r: Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\RelationProxy
Trace on line %d: $r: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, Tests\Psalm\LaravelPlugin\Sandbox\PivotShape\Owner, Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>&Countable&static
