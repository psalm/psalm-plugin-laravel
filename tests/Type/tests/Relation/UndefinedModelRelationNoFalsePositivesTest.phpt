--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-find-undefined-model-relations.xml
--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\DamageReport;
use App\Models\Part;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * UndefinedModelRelation must stay silent for valid relations (every syntax) and defer
 * whenever the model or the name cannot be resolved with confidence.
 * Empty EXPECTF asserts that nothing is reported.
 * See https://github.com/psalm/psalm-plugin-laravel/issues/643
 */

// Valid relations — every supported syntax.
Customer::with('vehicles');
Customer::with('vehicles.workOrders');           // dot-notation, both valid
Customer::with('vehicles.customer.vehicles');    // deep dot-notation
Customer::with(['vehicles', 'primaryVehicle']);  // array (list)
Customer::with('vehicles:id,name');              // select syntax
Customer::with(['vehicles' => function (Relation $query): void {
    $query->getResults();
}]);                                             // array (keyed/closure)
Customer::query()->whereHas('workOrders');
Customer::query()->has('vehicles');
Customer::doesntHave('vehicles');
Customer::query()->whereRelation('vehicles', 'active', 1);
Customer::query()->whereDoesntHaveRelation('vehicles', 'active', 1);

// morphTo intermediate: the target model is polymorphic, so deeper segments must NOT
// be flagged (defer). Both DamageReport::reportable() (@phpstan-return) and
// Part::orderedBy() (@return) annotate MorphTo<A|B, $this>; Psalm collapses the `$this`
// generic to a bare MorphTo, so the related side cannot be pinned and the walk defers.
// RelationResolver::singleModel() is the explicit backstop should a future Psalm keep
// the multi-model union instead of collapsing it.
DamageReport::with('reportable.anythingDeeperIsNotChecked');
Part::with('orderedBy.anythingDeeperIsNotChecked');

// Existence-only invariant: 'save' is a real Model method, not a relation. The rule
// reports only when no method exists, so a non-relation method must stay silent.
Customer::with('save');

function valid_relations_on_instance(Customer $c): void
{
    $c->load('vehicles');
    $c->loadMissing('primaryVehicle');
    $c->loadCount('vehicles');
    // Aggregate ` as alias` clause on a valid relation must not be flagged.
    $c->loadCount('vehicles as vehicle_total');
    $c->loadCount(['vehicles as active_total' => function (Builder $query): void {
        $query->whereNotNull('id');
    }]);
    $c->vehicles()->with('workOrders');          // relation receiver, valid
}

// Dynamic relation name — not statically known, must defer.
function dynamic_relation_name(Customer $c, string $name): void
{
    $c->load($name);
}

// Bare Builder<Model> receiver (un-narrowed) resolves to the base Model — the
// relation set is unknown, so the rule must defer rather than flag.
function bare_builder_receiver(Builder $builder): void
{
    $builder->with('anythingGoesHere');
}

// Non-model receiver that happens to expose a with() method — must defer.
$notAModel = new class {
    public function with(string $relations): string
    {
        return $relations;
    }
};
$notAModel->with('whatever');
?>
--EXPECTF--
