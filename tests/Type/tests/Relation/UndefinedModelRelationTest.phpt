--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-optin-custom-issues.xml
--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Validate relation names passed to with()/load()/has()/whereHas()/... against the
 * resolved model. See https://github.com/psalm/psalm-plugin-laravel/issues/643
 */

// String, static call (real static Model::with).
Customer::with('nonExistentRelation');

// Magic static call (has() forwards through Model::__callStatic).
Customer::query()->has('missingHasRelation');

// Instance builder call.
Customer::query()->with('typoWith');

// without() still accepts relationship names; an unknown key is usually a typo
// even though Laravel treats removing an absent eager-load key as a no-op.
Customer::query()->without('typoWithout');

// whereHas on a builder.
Customer::query()->whereHas('typoWhereHas');

// whereRelation (relation + column constraint).
Customer::query()->whereRelation('typoWhereRelation', 'active');

// Dot-notation: 'vehicles' is valid (HasMany<Vehicle>), the second segment is
// resolved against Vehicle and is undefined there.
Customer::with('vehicles.typoOnVehicle');

// Array (list) syntax: each element checked.
Customer::with(['vehicles', 'typoInArray']);

// Array (keyed/closure) syntax: the key is the relation name. The closure param is
// typed Relation to match the with() stub's callable signature.
Customer::with(['typoKeyed' => function (Relation $query): void {
    $query->getResults();
}]);

// Select syntax: the ':columns' part is stripped before checking.
Customer::with('typoSelect:id,name');

// ':columns' on a key paired with a Closure is NOT parsed by Laravel (the key is looked up
// verbatim), so the relation is reported with the verbatim name and a fix hint.
Customer::with(['vehicles:id,name' => fn (Relation $query) => $query]);
Customer::with(['vehicles:id' => function (Relation $query): void {
    $query->getResults();
}]);
Customer::query()->with(['vehicles:id' => fn (Relation $query) => $query]);
// Dot-notation still splits: the verbatim ':columns' lands on the last (or an inner) segment.
Customer::with(['vehicles.workOrders:id' => fn (Relation $query) => $query]);
Customer::with(['vehicles:id.workOrders' => fn (Relation $query) => $query]);
// Builder::with($relations, $callback) wraps the pair as [$relations => $callback].
Customer::query()->with('vehicles:id', fn (Relation $query) => $query);
Customer::query()->with(callback: fn (Relation $query) => $query, relations: 'vehicles:id');

// Relationship existence queries never parse ':columns' (the name is looked up verbatim), so the
// suffix is reported with a hint to remove it.
Customer::query()->has('vehicles:id');
Customer::query()->whereHas('vehicles:id', fn (Relation $q) => $q);
Customer::query()->has('vehicles.workOrders:id');
Customer::query()->whereRelation('vehicles:id', 'make', 'x');
Customer::query()->whereHas(relation: 'vehicles:id');

function undefined_relations_on_model_instance(Customer $c): void
{
    $c->load(['vehicles:id' => fn (Relation $query) => $query]);
    $c->loadMissing(['vehicles:id' => fn (Relation $query) => $query]);

    // load() on a loaded model.
    $c->load('typoLoad');

    // loadCount() — first arg is the relation, must still be validated.
    $c->loadCount('typoCount');

    // Aggregate ` as alias` clause is stripped, so a typo before ` as ` still fires.
    $c->loadCount('typoAggregate as cnt');

    // Relation receiver: $c->vehicles() is Relation<Vehicle>, so the name is
    // resolved against Vehicle.
    $c->vehicles()->with('typoOnRelation');
}
?>
--EXPECTF--
UndefinedModelRelation on line %d: Relation 'nonExistentRelation' is not defined on App\Models\Customer.
UndefinedModelRelation on line %d: Relation 'missingHasRelation' is not defined on App\Models\Customer.
UndefinedModelRelation on line %d: Relation 'typoWith' is not defined on App\Models\Customer.
UndefinedModelRelation on line %d: Relation 'typoWithout' is not defined on App\Models\Customer.
UndefinedModelRelation on line %d: Relation 'typoWhereHas' is not defined on App\Models\Customer.
UndefinedModelRelation on line %d: Relation 'typoWhereRelation' is not defined on App\Models\Customer.
UndefinedModelRelation on line %d: Relation 'typoOnVehicle' is not defined on App\Models\Vehicle.
UndefinedModelRelation on line %d: Relation 'typoInArray' is not defined on App\Models\Customer.
UndefinedModelRelation on line %d: Relation 'typoKeyed' is not defined on App\Models\Customer.
UndefinedModelRelation on line %d: Relation 'typoSelect' is not defined on App\Models\Customer.
UndefinedModelRelation on line %d: Relation 'vehicles:id,name' is not defined on App\Models\Customer: Laravel does not parse ':columns' on a relation paired with a constraint closure; select the columns inside the closure.
UndefinedModelRelation on line %d: Relation 'vehicles:id' is not defined on App\Models\Customer: Laravel does not parse ':columns' on a relation paired with a constraint closure; select the columns inside the closure.
UndefinedModelRelation on line %d: Relation 'vehicles:id' is not defined on App\Models\Customer: Laravel does not parse ':columns' on a relation paired with a constraint closure; select the columns inside the closure.
UndefinedModelRelation on line %d: Relation 'workOrders:id' is not defined on App\Models\Vehicle: Laravel does not parse ':columns' on a relation paired with a constraint closure; select the columns inside the closure.
UndefinedModelRelation on line %d: Relation 'vehicles:id' is not defined on App\Models\Customer: Laravel does not parse ':columns' on a relation paired with a constraint closure; select the columns inside the closure.
UndefinedModelRelation on line %d: Relation 'vehicles:id' is not defined on App\Models\Customer: Laravel does not parse ':columns' on a relation paired with a constraint closure; select the columns inside the closure.
UndefinedModelRelation on line %d: Relation 'vehicles:id' is not defined on App\Models\Customer: Laravel does not parse ':columns' on a relation paired with a constraint closure; select the columns inside the closure.
UndefinedModelRelation on line %d: Relation 'vehicles:id' is not defined on App\Models\Customer: Laravel does not parse ':columns' in a relationship existence query; remove the suffix.
UndefinedModelRelation on line %d: Relation 'vehicles:id' is not defined on App\Models\Customer: Laravel does not parse ':columns' in a relationship existence query; remove the suffix.
UndefinedModelRelation on line %d: Relation 'workOrders:id' is not defined on App\Models\Vehicle: Laravel does not parse ':columns' in a relationship existence query; remove the suffix.
UndefinedModelRelation on line %d: Relation 'vehicles:id' is not defined on App\Models\Customer: Laravel does not parse ':columns' in a relationship existence query; remove the suffix.
UndefinedModelRelation on line %d: Relation 'vehicles:id' is not defined on App\Models\Customer: Laravel does not parse ':columns' in a relationship existence query; remove the suffix.
UndefinedModelRelation on line %d: Relation 'vehicles:id' is not defined on App\Models\Customer: Laravel does not parse ':columns' on a relation paired with a constraint closure; select the columns inside the closure.
UndefinedModelRelation on line %d: Relation 'vehicles:id' is not defined on App\Models\Customer: Laravel does not parse ':columns' on a relation paired with a constraint closure; select the columns inside the closure.
UndefinedModelRelation on line %d: Relation 'typoLoad' is not defined on App\Models\Customer.
UndefinedModelRelation on line %d: Relation 'typoCount' is not defined on App\Models\Customer.
UndefinedModelRelation on line %d: Relation 'typoAggregate' is not defined on App\Models\Customer.
UndefinedModelRelation on line %d: Relation 'typoOnRelation' is not defined on App\Models\Vehicle.
