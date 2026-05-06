--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;
use App\Models\Part;
use App\Models\PartReplacement;
use App\Models\PowerTool;
use App\Models\Tool;
use App\Models\WorkOrderNote;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/879.
 *
 * Relation factories called with self::class / static::class / parent::class
 * must resolve the keyword to the declaring (or parent) class FQCN. Without
 * the fix, the literal keyword leaks as TRelatedModel and Psalm substitutes
 * `self` with the call site's enclosing class — producing nonsensical
 * "expects MergeTagsAction" errors on save() / associate() at the consumer.
 *
 * Same root cause hits ->using(self::class) on BelongsToMany / MorphToMany.
 *
 * The supporting fixtures live on disk so the plugin's
 * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler}
 * (which guards on `class_exists()` via the autoloader) registers the
 * relation provider for them. Inline-defined models in PHPT bodies are
 * not autoloadable and bypass the registration path, so they cannot
 * exercise the handler under test.
 */

// --- Reproducer 1: hasMany(self::class) → ->save() ---

function issue879_hasMany_self_class_resolves_to_declaring(WorkOrderNote $parent): HasMany
{
    $relation = $parent->replies();
    /** @psalm-check-type-exact $relation = HasMany<WorkOrderNote, WorkOrderNote> */
    return $relation;
}

function issue879_hasMany_self_class_save_accepts_same_model(WorkOrderNote $parent, WorkOrderNote $reply): WorkOrderNote|false
{
    return $parent->replies()->save($reply);
}

// --- Reproducer 2: belongsTo(self::class) → ->associate() ---

function issue879_belongsTo_self_class_resolves_to_declaring(WorkOrderNote $reply): BelongsTo
{
    $relation = $reply->parent();
    /** @psalm-check-type-exact $relation = BelongsTo<WorkOrderNote, WorkOrderNote> */
    return $relation;
}

function issue879_belongsTo_self_class_associate_accepts_same_model(WorkOrderNote $reply, WorkOrderNote $parent): WorkOrderNote
{
    return $reply->parent()->associate($parent);
}

// --- self::class on a base class resolves to the declaring class ---

function issue879_self_class_on_base_resolves_to_declaring(Tool $tool): HasOne
{
    $relation = $tool->replacementTool();
    /** @psalm-check-type-exact $relation = HasOne<Tool, Tool> */
    return $relation;
}

// static::class is conservatively resolved to the declaring class — strictly better
// than leaking the literal `'static'` keyword. Late-static-binding-correct resolution
// would require the binding class threaded into the parser cache; that's out of scope
// for the issue, but pinned here so a future "fix" cannot silently regress.

function issue879_static_class_resolves_to_declaring_conservatively(Tool $tool): HasOne
{
    $relation = $tool->lateBoundReplacement();
    /** @psalm-check-type-exact $relation = HasOne<Tool, Tool> */
    return $relation;
}

// --- parent::class resolves to the immediate parent FQCN ---

function issue879_parent_class_resolves_to_parent_fqcn(PowerTool $powerTool): HasMany
{
    $relation = $powerTool->ancestorTools();
    /** @psalm-check-type-exact $relation = HasMany<Tool, PowerTool> */
    return $relation;
}

// --- self::class / parent::class at the through-relation intermediate slot ---
// extractClassStringArg(positionalIndex: 1, paramName: 'through', ...) is reachable
// only via hasOneThrough / hasManyThrough. Without these tests, a refactor that
// silently dropped $declaringClass / $parentClass at index 1 would go undetected.

function issue879_through_intermediate_self_class(Tool $tool): HasManyThrough
{
    $relation = $tool->successorMechanics();
    /** @psalm-check-type-exact $relation = HasManyThrough<Mechanic, Tool, Tool> */
    return $relation;
}

// Named-arg form (`through: parent::class`) hits the named-arg branch of
// extractClassStringArg in addition to the through index — pinning that the
// fix threads the resolution context through both branches.

function issue879_through_intermediate_parent_class_named_arg(PowerTool $powerTool): HasManyThrough
{
    $relation = $powerTool->lineageMechanics();
    /** @psalm-check-type-exact $relation = HasManyThrough<Mechanic, Tool, PowerTool> */
    return $relation;
}

// --- ->using(self::class) on a BelongsToMany threads through firstClassStringArg ---

function issue879_using_self_class_resolves_pivot(PartReplacement $replacement): BelongsToMany
{
    $relation = $replacement->bundledReplacements();
    /** @psalm-check-type-exact $relation = BelongsToMany<Part, PartReplacement, PartReplacement, 'pivot'> */
    return $relation;
}
?>
--EXPECTF--
