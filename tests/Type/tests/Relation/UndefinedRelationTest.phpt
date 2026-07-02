--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-find-undefined-relations.xml
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
Customer::has('missingHasRelation');

// Instance builder call.
Customer::query()->with('typoWith');

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

function undefined_relations_on_model_instance(Customer $c): void
{
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
UndefinedRelation on line %d: Relation 'nonExistentRelation' is not defined on App\Models\Customer.
UndefinedRelation on line %d: Relation 'missingHasRelation' is not defined on App\Models\Customer.
UndefinedRelation on line %d: Relation 'typoWith' is not defined on App\Models\Customer.
UndefinedRelation on line %d: Relation 'typoWhereHas' is not defined on App\Models\Customer.
UndefinedRelation on line %d: Relation 'typoWhereRelation' is not defined on App\Models\Customer.
UndefinedRelation on line %d: Relation 'typoOnVehicle' is not defined on App\Models\Vehicle.
UndefinedRelation on line %d: Relation 'typoInArray' is not defined on App\Models\Customer.
UndefinedRelation on line %d: Relation 'typoKeyed' is not defined on App\Models\Customer.
UndefinedRelation on line %d: Relation 'typoSelect' is not defined on App\Models\Customer.
UndefinedRelation on line %d: Relation 'typoLoad' is not defined on App\Models\Customer.
UndefinedRelation on line %d: Relation 'typoCount' is not defined on App\Models\Customer.
UndefinedRelation on line %d: Relation 'typoAggregate' is not defined on App\Models\Customer.
UndefinedRelation on line %d: Relation 'typoOnRelation' is not defined on App\Models\Vehicle.
