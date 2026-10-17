--FILE--
<?php declare(strict_types=1);

// Dedicated sub-namespace: psalm-tester batches every .phpt into one analysis, so the
// fixture class names must not collide with the other Relation tests.
namespace Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape;

use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/1944 (point 1).
 *
 * `withPivot('role')->withTimestamps()` used to return the stub's `$this`, i.e. the plain
 * `BelongsToMany<User, Team, Pivot, 'pivot'>`. A relation method declared with Laravel's own
 * `@return BelongsToMany<User, self, Pivot&object{role: string}, 'pivot'>` therefore failed with
 * InvalidReturnType/InvalidReturnStatement on a chain the user cannot annotate any differently.
 *
 * The `withPivot()` / `withTimestamps()` call-site handler now refines the pivot slot (3rd template
 * param) to `<Pivot>&object{col: type, ...}`, and `TPivotModel` is `@template-covariant` so a refined
 * inferred pivot still satisfies an existing plain `Pivot` (or 2-param) declaration.
 *
 * This harness boots Testbench with no migrations, so column read types are never schema-derived here:
 * ordinary columns are `mixed`; timestamp columns are `Carbon|null` once the plain pivot's own `created_at`
 * column is selected (that is when Laravel casts them). The schema-typed path (`role:string`,
 * `level:int|null`) is covered by PivotSchemaColumnsEmissionTest. Points 2 and 3 of the issue (property reads
 * through the shape) are an upstream Psalm bug, pinned by PivotShapePropertyFetchKnownLimitation.phpt.
 * The parent's timestamp column names are read from a live instance, so `withTimestamps()` only refines for a
 * parent loadable in the Psalm process. Classes declared in this phpt (Team) are not, so they decline.
 *
 * `@psalm-check-type-exact` compares object shapes laxly (keys and column types are not checked), so the
 * shape contents are pinned with `@psalm-trace`, whose output is the exact type id. Every method below must
 * also analyse clean, so the `--EXPECTF--` block lists traces only.
 */
class User extends Model
{
}

class Team extends Model
{
    // The issue's exact reproducer. The declared shape is satisfied by the refined chain.
    /** @return BelongsToMany<User, self, Pivot&object{role: string}, 'pivot'> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    // Covariance: a plain declaration must keep accepting a refined inferred pivot.
    /** @return BelongsToMany<User, $this, Pivot, 'pivot'> */
    public function plainDeclared(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role');
    }

    // Covariance: the complete 4-param plain form, the shape every pre-#1944 user wrote.
    /** @return BelongsToMany<User, self, Pivot, 'pivot'> */
    public function plainFourParams(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role', 'level')->withTimestamps();
    }

    // Covariance for the MorphToMany header.
    /** @return MorphToMany<User, self, MorphPivot, 'pivot'> */
    public function morphPlain(): MorphToMany
    {
        return $this->morphToMany(User::class, 'taggable')->withPivot('note');
    }

    // A declared shape on MorphToMany.
    /** @return MorphToMany<User, self, MorphPivot&object{note: string}, 'pivot'> */
    public function morphShaped(): MorphToMany
    {
        return $this->morphToMany(User::class, 'taggable')->withPivot('note');
    }

    // Variadic form: every argument is a column, in call order.
    public function variadicColumns(): void
    {
        $_ = $this->belongsToMany(User::class)->withPivot('role', 'level');
        /** @psalm-trace $_ */
    }

    // Array form: only the first argument counts; later arguments are ignored at runtime.
    public function arrayForm(): void
    {
        $_ = $this->belongsToMany(User::class)->withPivot(['a', 'b'], 'ignored');
        /** @psalm-trace $_ */
    }

    // Repeated calls merge: existing keys are kept and new keys appended.
    public function repeatedCallsMerge(): void
    {
        $_ = $this->belongsToMany(User::class)->withPivot('a')->withPivot('b');
        /** @psalm-trace $_ */
    }

    // Merging does not duplicate a repeated column.
    public function mergeDoesNotDuplicate(): void
    {
        $_ = $this->belongsToMany(User::class)->withPivot('a')->withPivot('a', 'b');
        /** @psalm-trace $_ */
    }

    // withTimestamps() alone declines here: Team is declared inside this phpt, so it is not loadable
    // at analysis time, its timestamp column names are unknown, and the plugin declines instead of
    // guessing (the slot stays plain Pivot and the stub's `$this` is kept). Positive coverage with a
    // loadable parent: loadable_parent_timestamps_alone().
    public function timestampsAlone(): void
    {
        $_ = $this->belongsToMany(User::class)->withTimestamps();
        /** @psalm-trace $_ */
    }

    // Declines for the same reason as timestampsAlone(): the withPivot column survives, but the
    // unknown-parent timestamp columns are not appended.
    public function pivotThenTimestamps(): void
    {
        $_ = $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
        /** @psalm-trace $_ */
    }

    // false skips a column, a string literal names it. A custom-named column is not date-cast.
    public function customTimestampColumn(): void
    {
        $_ = $this->belongsToMany(User::class)->withTimestamps('joined_at', false);
        /** @psalm-trace $_ */
    }

    // as() re-narrows only TAccessor; the refined pivot slot survives.
    public function asAfterWithPivot(): void
    {
        $_ = $this->belongsToMany(User::class)->withPivot('role')->as('membership');
        /** @psalm-trace $_ */
    }

    // Order-independent with as(): the accessor swap does not drop columns added afterwards.
    public function asBeforeWithPivot(): void
    {
        $_ = $this->belongsToMany(User::class)->as('membership')->withPivot('role');
        /** @psalm-trace $_ */
    }

    // using() narrows the pivot class first, the shape is added on top of the custom class.
    public function usingThenWithPivot(): void
    {
        $_ = $this->belongsToMany(User::class)->using(CustomPivot::class)->withPivot('role');
        /** @psalm-trace $_ */
    }

    // MorphToMany resolves through the InteractsWithPivotTable trait registration, MorphPivot slot.
    public function morphToManyWithPivot(): void
    {
        $_ = $this->morphToMany(User::class, 'taggable')->withPivot('note');
        /** @psalm-trace $_ */
    }

    // A refined chain keeps flowing through a Builder-forwarded call.
    public function refinedChainThroughBuilderCall(): void
    {
        $_ = $this->belongsToMany(User::class)->withPivot('role')->wherePivot('role', 'admin');
        /** @psalm-trace $_ */
    }

    // Decline: an unpacked argument may expand to any names; the slot stays plain Pivot.
    /** @param non-empty-list<string> $cols */
    public function declinesUnpackedArgument(array $cols): void
    {
        $_ = $this->belongsToMany(User::class)->withPivot(...$cols);
        /** @psalm-trace $_ */
    }

    // Decline: a non-literal column name; the slot stays plain Pivot.
    public function declinesNonLiteralColumn(string $col): void
    {
        $_ = $this->belongsToMany(User::class)->withPivot($col);
        /** @psalm-trace $_ */
    }

    // Decline: a keyed array item is not a column list.
    public function declinesKeyedArray(): void
    {
        $_ = $this->belongsToMany(User::class)->withPivot(['x' => 'role']);
        /** @psalm-trace $_ */
    }

    // Decline: a non-literal withTimestamps() column name contributes nothing.
    public function declinesNonLiteralTimestamp(string $col): void
    {
        $_ = $this->belongsToMany(User::class)->withTimestamps($col);
        /** @psalm-trace $_ */
    }
}

class CustomPivot extends Pivot
{
}

/**
 * A userland relation subclass that re-declares the four BelongsToMany templates in the same positions.
 * withPivot() is declared on the trait, whose handler registration is the only one that fires for subclasses.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 * @template TPivotModel of Pivot = Pivot
 * @template TAccessor of string = 'pivot'
 * @extends BelongsToMany<TRelatedModel, TDeclaringModel, TPivotModel, TAccessor>
 */
final class MyRel extends BelongsToMany
{
}

/**
 * A userland relation subclass that reorders the templates, so its 3rd template param is NOT the pivot
 * slot. Refining "the 3rd param" would corrupt it (here: `Team&object{x: mixed}`), so the handler must decline.
 *
 * @template TA of Model
 * @template TP of Pivot
 * @template TD of Model
 * @template TAcc of string
 * @extends BelongsToMany<TA, TD, TP, TAcc>
 */
class Weird extends BelongsToMany
{
}

/** @param MyRel<User, Team, Pivot, 'pivot'> $rel */
function userland_receiver(MyRel $rel): void
{
    $_ = $rel->withPivot('role', 'level');
    /** @psalm-trace $_ */
}

/**
 * @param Weird<User, CustomPivot, Team, 'pivot'> $w
 * @return Weird<User, CustomPivot, Team, 'pivot'>
 */
function takes_weird(Weird $w): Weird
{
    return $w;
}

/** @param Weird<User, CustomPivot, Team, 'pivot'> $w */
function reordered_templates_decline(Weird $w): void
{
    // Declined: the result stays the stub's `$this` type and is still accepted by the original declaration.
    $_ = $w->withPivot('x');
    /** @psalm-trace $_ */
    takes_weird($_);
}

// Call sites typed through the model method (ModelRelationReturnTypeHandler) carry the shape parsed from
// the method body even though WorkOrder::parts() is declared with a plain 2-param
// `@psalm-return BelongsToMany<Part, $this>`. WorkOrder and Part are the autoloaded application fixture
// models (relation call-site typing needs loadable model classes).
function call_site_body_chain_relation(WorkOrder $order): void
{
    $_ = $order->parts();
    /** @psalm-trace $_ */
}

// The shape also survives a terminal call on the model-method relation.
function call_site_body_chain_terminal(WorkOrder $order): void
{
    $relation = $order->parts();

    $_ = $relation->firstOrFail();
    /** @psalm-trace $_ */
}

// Positive timestamp coverage needs a parent model that is loadable in the Psalm process, so the
// default created_at/updated_at names can be resolved: the phpt-local Team above cannot be (see
// timestampsAlone()). Part is an autoloaded fixture, reached through its public relation method.
function loadable_parent_timestamps_alone(\App\Models\Part $part): void
{
    $_ = $part->workOrders()->withTimestamps();
    /** @psalm-trace $_ */
}

// Same loadable parent: columns from the body-chain withPivot('quantity', 'unit_price') come first,
// the resolved timestamp columns are appended.
function loadable_parent_pivot_then_timestamps(WorkOrder $order): void
{
    $_ = $order->parts()->withTimestamps();
    /** @psalm-trace $_ */
}

?>
--EXPECTF--
Trace on line %d: $_: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team&static, Illuminate\Database\Eloquent\Relations\Pivot&object{role:mixed, level:mixed}, 'pivot'>
Trace on line %d: $_: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team&static, Illuminate\Database\Eloquent\Relations\Pivot&object{a:mixed, b:mixed}, 'pivot'>
Trace on line %d: $_: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team&static, Illuminate\Database\Eloquent\Relations\Pivot&object{a:mixed, b:mixed}, 'pivot'>
Trace on line %d: $_: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team&static, Illuminate\Database\Eloquent\Relations\Pivot&object{a:mixed, b:mixed}, 'pivot'>
Trace on line %d: $_: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team&static, Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>&static
Trace on line %d: $_: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team&static, Illuminate\Database\Eloquent\Relations\Pivot&object{role:mixed}, 'pivot'>&static
Trace on line %d: $_: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team&static, Illuminate\Database\Eloquent\Relations\Pivot&object{joined_at:mixed}, 'pivot'>
Trace on line %d: $_: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team&static, Illuminate\Database\Eloquent\Relations\Pivot&object{role:mixed}, 'membership'>&static
Trace on line %d: $_: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team&static, Illuminate\Database\Eloquent\Relations\Pivot&object{role:mixed}, 'membership'>&static
Trace on line %d: $_: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team&static, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\CustomPivot&object{role:mixed}, 'pivot'>&static
Trace on line %d: $_: Illuminate\Database\Eloquent\Relations\MorphToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team&static, Illuminate\Database\Eloquent\Relations\MorphPivot&object{note:mixed}, 'pivot'>
Trace on line %d: $_: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team&static, Illuminate\Database\Eloquent\Relations\Pivot&object{role:mixed}, 'pivot'>&static
Trace on line %d: $_: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team&static, Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>&static
Trace on line %d: $_: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team&static, Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>&static
Trace on line %d: $_: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team&static, Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>&static
Trace on line %d: $_: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team&static, Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>&static
Trace on line %d: $_: Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\MyRel<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team, Illuminate\Database\Eloquent\Relations\Pivot&object{role:mixed, level:mixed}, 'pivot'>
Trace on line %d: $_: Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Weird<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\CustomPivot, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team, 'pivot'>&static
Trace on line %d: $_: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, App\Models\WorkOrder, Illuminate\Database\Eloquent\Relations\Pivot&object{quantity:mixed, unit_price:mixed}, 'pivot'>
Trace on line %d: $_: App\Models\Part&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot&object{quantity:mixed, unit_price:mixed}}
Trace on line %d: $_: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\WorkOrder, App\Models\Part, Illuminate\Database\Eloquent\Relations\Pivot&object{created_at:Illuminate\Support\Carbon|null, updated_at:Illuminate\Support\Carbon|null}, 'pivot'>
Trace on line %d: $_: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\Part, App\Models\WorkOrder, Illuminate\Database\Eloquent\Relations\Pivot&object{quantity:mixed, unit_price:mixed, created_at:Illuminate\Support\Carbon|null, updated_at:Illuminate\Support\Carbon|null}, 'pivot'>
