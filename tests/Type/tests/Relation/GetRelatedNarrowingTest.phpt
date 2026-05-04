--FILE--
<?php declare(strict_types=1);

use App\Models\Admin;
use App\Models\Customer;
use App\Models\DamageReport;
use App\Models\Invoice;
use App\Models\Mechanic;
use App\Models\MechanicSpecialization;
use App\Models\Part;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Verify that user-defined relationship methods on Models return the precisely
 * templated relation type so downstream calls (`getRelated()`, `getParent()`,
 * `get()`, etc.) resolve through the existing stub annotations.
 *
 * Without ModelRelationReturnTypeHandler, Psalm collapses generics on these
 * methods to upper bounds — e.g. `(new WorkOrder())->invoice()` resolves to
 * `HasOne<Model, Model>`, and `getRelated()` returns `Model` instead of `Invoice`.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/760
 */

// hasOne
function test_hasOne_getRelated_narrows_to_Invoice(): Invoice
{
    return (new WorkOrder())->invoice()->getRelated();
}

// belongsTo
function test_belongsTo_getRelated_narrows_to_Vehicle(): Vehicle
{
    return (new WorkOrder())->vehicle()->getRelated();
}

// belongsToMany
function test_belongsToMany_getRelated_narrows_to_Part(): Part
{
    return (new WorkOrder())->parts()->getRelated();
}

// hasMany
function test_hasMany_getRelated_narrows_to_Vehicle(): Vehicle
{
    return (new Customer())->vehicles()->getRelated();
}

// hasOneThrough — three template params, exercises argIndex=1 in the parser
function test_hasOneThrough_getRelated_narrows_to_Customer(): Customer
{
    return (new Mechanic())->vehicleOwner()->getRelated();
}

// hasManyThrough — three template params (related, intermediate, declaring)
function test_hasManyThrough_getRelated_narrows_to_WorkOrder(): WorkOrder
{
    return (new Customer())->workOrders()->getRelated();
}

// morphOne
function test_morphOne_getRelated_narrows_to_DamageReport(): DamageReport
{
    return (new Vehicle())->latestReport()->getRelated();
}

// morphMany
function test_morphMany_getRelated_narrows_to_DamageReport(): DamageReport
{
    return (new WorkOrder())->damageReports()->getRelated();
}

// morphToMany
function test_morphToMany_getRelated_narrows_to_WorkOrder(): WorkOrder
{
    return (new Admin())->workOrders()->getRelated();
}

// morphedByMany — same Relation class as morphToMany, inverse direction
function test_morphedByMany_getRelated_narrows_to_Admin(): Admin
{
    return (new Customer())->bookmarkedAdmins()->getRelated();
}

// Chained call — the original issue's representative shape
function test_getRelated_chained_call_resolves(): string
{
    return (new WorkOrder())->invoice()->getRelated()->getKeyName();
}

// getParent() — second template param resolves to the declaring model
function test_getParent_narrows_to_declaring_model(): WorkOrder
{
    return (new WorkOrder())->invoice()->getParent();
}

// Through-relation getParent() pins which template slot wins.
// HasManyThrough<TRelatedModel, TIntermediateModel, TDeclaringModel> binds the
// parent Relation's second template (TDeclaringModel-of-Relation) to TIntermediateModel,
// not to the far parent — see stubs/common/Database/Eloquent/Relations/HasOneOrManyThrough.stubphp.
// At runtime $parent is the immediate "throughParent" (Vehicle), matching Laravel's
// HasOneOrManyThrough constructor. This test locks the contract so a stub edit can't
// silently flip it.
function test_hasManyThrough_getParent_returns_intermediate(): Vehicle
{
    return (new Customer())->workOrders()->getParent();
}

// belongsTo without @psalm-return — relies entirely on the handler-emitted generics.
// Verifies the handler covers methods declared with only the abstract relation class
// (`: BelongsTo`), the original failure shape from issue #760.
function test_belongsTo_no_psalm_return_resolves(): Mechanic
{
    return (new WorkOrder())->mechanic()->getRelated();
}

// morphTo with a docblock-declared candidate set narrows getRelated() to that union.
// DamageReport::reportable() is annotated `@phpstan-return MorphTo<Vehicle|WorkOrder, $this>`;
// the handler reads that via RelationMethodParser::extractDocblockRelatedModelType.
function test_morphTo_with_docblock_narrows_to_union(): Vehicle|WorkOrder
{
    return (new DamageReport())->reportable()->getRelated();
}

// morphTo without a docblock — the handler defers, the stub's `MorphTo<Model, $this>`
// default applies. What matters here is that no MixedMethodCall fires on the chain.
function test_morphTo_no_docblock_does_not_break(\Illuminate\Database\Eloquent\Relations\MorphTo $r): string
{
    return $r->getRelated()->getKeyName();
}

// Plain Relation parameter — handler is not registered on Relation, so the stub's
// `@return TRelatedModel` resolves to the upper bound `Model`. Cascading calls on
// the result must remain valid (no MixedMethodCall).
function test_plain_relation_parameter_returns_model(Relation $relation): string
{
    $record = $relation->getRelated();
    /** @psalm-check-type-exact $record = Model */
    return $record->getKeyName();
}

// belongsToMany with `->using(CustomPivot::class)`: the handler still narrows
// TRelatedModel and TDeclaringModel — getRelated() resolves correctly. TPivotModel
// rebinding via `using()` is a known Psalm 7 limitation (the stub's
// `@psalm-this-out` does not propagate, and the user's `@psalm-return` annotation
// also collapses when `$this` cannot be substituted), so the pivot defaults to
// Pivot. The primary fix from #760 — getRelated() not returning Model — is
// preserved.
function test_using_chain_still_narrows_getRelated(): MechanicSpecialization
{
    return (new Mechanic())->specializationsWithPivot()->getRelated();
}

?>
--EXPECTF--
