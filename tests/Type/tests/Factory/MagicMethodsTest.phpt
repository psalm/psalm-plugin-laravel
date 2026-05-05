--FILE--
<?php declare(strict_types=1);

use App\Factories\WorkOrderFactory;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/696
 *
 * Factory subclasses expose magic for{Relation}() / has{Relation}() methods derived
 * from the associated model's relationship methods. Laravel's Factory::__call
 * dispatches these dynamically; the plugin must recognize them at static-analysis
 * time so chained builder calls type-check.
 *
 * WorkOrder has these relationships:
 *   - vehicle()    : BelongsTo<Vehicle>
 *   - mechanic()   : BelongsTo<Mechanic>
 *   - invoice()    : HasOne<Invoice>
 *   - parts()      : BelongsToMany<Part>
 */

// for{Relation}() — single relation, BelongsTo.
function test_for_belongs_to(WorkOrderFactory $factory): WorkOrderFactory
{
    /** @psalm-check-type-exact $chained = WorkOrderFactory */
    $chained = $factory->forVehicle();
    return $chained;
}

// for{Relation}() — second BelongsTo on the same model.
function test_for_second_belongs_to(WorkOrderFactory $factory): WorkOrderFactory
{
    /** @psalm-check-type-exact $chained = WorkOrderFactory */
    $chained = $factory->forMechanic();
    return $chained;
}

// has{Relation}() — HasOne with no count.
function test_has_one(WorkOrderFactory $factory): WorkOrderFactory
{
    /** @psalm-check-type-exact $chained = WorkOrderFactory */
    $chained = $factory->hasInvoice();
    return $chained;
}

// has{Relation}(int) — BelongsToMany with explicit count.
function test_has_many_with_count(WorkOrderFactory $factory): WorkOrderFactory
{
    /** @psalm-check-type-exact $chained = WorkOrderFactory */
    $chained = $factory->hasParts(3);
    return $chained;
}

// Chained for / has — preserves the factory type across multiple magic dispatches.
function test_chained_for_and_has(WorkOrderFactory $factory): WorkOrderFactory
{
    /** @psalm-check-type-exact $chained = WorkOrderFactory */
    $chained = $factory->forVehicle()->hasParts(3)->forMechanic();
    return $chained;
}

// for{Relation}(array) — passes a state array to the related factory.
function test_for_with_state_array(WorkOrderFactory $factory): WorkOrderFactory
{
    /** @psalm-check-type-exact $chained = WorkOrderFactory */
    $chained = $factory->forVehicle(['make' => 'Toyota']);
    return $chained;
}

// has{Relation}(array, array) — sequence-of-arrays form supported by Factory::__call.
function test_has_with_sequence(WorkOrderFactory $factory): WorkOrderFactory
{
    /** @psalm-check-type-exact $chained = WorkOrderFactory */
    $chained = $factory->hasParts(['unit_price' => 10], ['unit_price' => 20]);
    return $chained;
}

// has{Relation}() — MorphMany. Exercises the morph branch of the Relation
// subclass detection in isRelationshipMethod (WorkOrder::damageReports returns
// MorphMany rather than HasMany).
function test_has_morph_many(WorkOrderFactory $factory): WorkOrderFactory
{
    /** @psalm-check-type-exact $chained = WorkOrderFactory */
    $chained = $factory->hasDamageReports(2);
    return $chained;
}
?>
--EXPECTF--
