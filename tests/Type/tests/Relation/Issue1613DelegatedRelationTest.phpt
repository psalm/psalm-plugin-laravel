--FILE--
<?php declare(strict_types=1);

use App\Collections\WorkOrderCollection;
use App\Models\AbstractDocument;
use App\Models\Contract;
use App\Models\DamageReport;
use App\Models\Invoice;
use App\Models\Mechanic;
use App\Models\MechanicSpecialization;
use App\Models\Part;
use App\Models\Receipt;
use App\Models\Shop;
use App\Models\SpecializationPivot;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/1613 (delegated shape).
 *
 * A relation method whose body is `return $this->otherRelation()->chain()` (bare native return
 * type, no docblock generic) used to collapse to the stub default `Relation<Model, Model>`: the
 * body parser recognised only relation factory calls. The delegated call now resolves like a
 * top-level one (own, trait-hosted or inherited method), keeping through and pivot slots. An outer
 * `->using()` / `->as()` overrides the delegated relation's own one, matching runtime order. The
 * delegated method follows runtime dispatch: the receiver's override wins, while a private method
 * binds to the class whose body (or composed trait) makes the call.
 */

function issue1613_delegated_own(WorkOrder $workOrder): MorphMany
{
    $relation = $workOrder->latestDamageReports();
    /** @psalm-check-type-exact $relation = MorphMany<DamageReport, WorkOrder> */
    return $relation;
}

function issue1613_delegated_to_trait(WorkOrder $workOrder): HasMany
{
    $relation = $workOrder->recentRevisions();
    /** @psalm-check-type-exact $relation = HasMany<WorkOrder, WorkOrder> */
    return $relation;
}

function issue1613_delegated_to_inherited(Contract $contract): HasMany
{
    $relation = $contract->signedParts();
    /** @psalm-check-type-exact $relation = HasMany<Part, Contract> */
    return $relation;
}

function issue1613_delegated_to_trait_on_abstract(AbstractDocument $document): HasMany
{
    $relation = $document->draftRevisions();
    /** @psalm-check-type-exact $relation = HasMany<AbstractDocument, AbstractDocument> */
    return $relation;
}

function issue1613_delegation_follows_receiver_override(Receipt $receipt): HasMany
{
    $relation = $receipt->pendingParts();
    /** @psalm-check-type-exact $relation = HasMany<Invoice, Receipt> */
    return $relation;
}

function issue1613_delegation_without_override(Contract $contract): HasMany
{
    $relation = $contract->pendingParts();
    /** @psalm-check-type-exact $relation = HasMany<Part, Contract> */
    return $relation;
}

function issue1613_private_helper_binds_to_composing_class(Contract $contract): HasMany
{
    $relation = $contract->priorRevisions();
    /** @psalm-check-type-exact $relation = HasMany<AbstractDocument, Contract> */
    return $relation;
}

function issue1613_private_helper_on_direct_composer(WorkOrder $workOrder): HasMany
{
    $relation = $workOrder->priorRevisions();
    /** @psalm-check-type-exact $relation = HasMany<WorkOrder, WorkOrder> */
    return $relation;
}

function issue1613_delegated_hasmany(Shop $shop): HasMany
{
    $relation = $shop->openWorkOrders();
    /** @psalm-check-type-exact $relation = HasMany<WorkOrder, Shop> */
    return $relation;
}

function issue1613_several_returns_of_one_relation(Shop $shop): HasMany
{
    $relation = $shop->sortedWorkOrders();
    /** @psalm-check-type-exact $relation = HasMany<WorkOrder, Shop> */
    return $relation;
}

function issue1613_delegated_through(Shop $shop): HasManyThrough
{
    $relation = $shop->seniorMechanics();
    /** @psalm-check-type-exact $relation = HasManyThrough<Mechanic, Vehicle, Shop> */
    return $relation;
}

function issue1613_delegated_pivot_outer_accessor_wins(Mechanic $mechanic): BelongsToMany
{
    $relation = $mechanic->specializationProfiles();
    /** @psalm-check-type-exact $relation = BelongsToMany<MechanicSpecialization, Mechanic, SpecializationPivot, 'profile'> */
    return $relation;
}

/** @return WorkOrderCollection<int, WorkOrder> */
function issue1613_delegated_relation_property(Shop $shop): WorkOrderCollection
{
    /** @psalm-check-type-exact $workOrders = WorkOrderCollection<int, WorkOrder> */
    $workOrders = $shop->openWorkOrders;
    return $workOrders;
}
?>
--EXPECTF--
