--FILE--
<?php declare(strict_types=1);

use App\Models\AbstractDocument;
use App\Models\Contract;
use App\Models\DamageReport;
use App\Models\Mechanic;
use App\Models\MechanicSpecialization;
use App\Models\Part;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/1613 (trait-hosted shape).
 *
 * {@see \App\Models\Concerns\HasRevisions} declares bare native relation return types. Psalm keeps
 * no method storage for a trait method on the composing model, so the relation body used to go
 * unparsed and the call collapsed to the stub default `HasMany<Model, Model>`.
 * `self::class` / `static::class` in the trait bind to the composing class: WorkOrder composes the
 * trait directly; Contract inherits it from AbstractDocument, so `self` is AbstractDocument while
 * TDeclaringModel binds to the receiver.
 */

function issue1613_trait_self_class(WorkOrder $workOrder): HasMany
{
    $relation = $workOrder->revisions();
    /** @psalm-check-type-exact $relation = HasMany<WorkOrder, WorkOrder> */
    return $relation;
}

function issue1613_trait_static_class(WorkOrder $workOrder): BelongsTo
{
    $relation = $workOrder->revisedFrom();
    /** @psalm-check-type-exact $relation = BelongsTo<WorkOrder, WorkOrder> */
    return $relation;
}

function issue1613_trait_literal_class(WorkOrder $workOrder): BelongsTo
{
    $relation = $workOrder->revisionAuthor();
    /** @psalm-check-type-exact $relation = BelongsTo<Mechanic, WorkOrder> */
    return $relation;
}

function issue1613_trait_on_parent(Contract $contract): HasMany
{
    $relation = $contract->revisions();
    /** @psalm-check-type-exact $relation = HasMany<AbstractDocument, Contract> */
    return $relation;
}

function issue1613_trait_on_abstract_receiver(AbstractDocument $document): BelongsTo
{
    $relation = $document->revisedFrom();
    /** @psalm-check-type-exact $relation = BelongsTo<AbstractDocument, AbstractDocument> */
    return $relation;
}

function issue1613_nested_trait_single_path(Supplier $supplier): BelongsTo
{
    $relation = $supplier->revisedFrom();
    /** @psalm-check-type-exact $relation = BelongsTo<Supplier, Supplier> */
    return $relation;
}

function issue1613_nested_trait_delegation(Supplier $supplier): HasMany
{
    $relation = $supplier->priorRevisions();
    /** @psalm-check-type-exact $relation = HasMany<Supplier, Supplier> */
    return $relation;
}

function issue1613_middle_trait_single_owner(MechanicSpecialization $specialization): BelongsTo
{
    $relation = $specialization->revisedFrom();
    /** @psalm-check-type-exact $relation = BelongsTo<MechanicSpecialization, MechanicSpecialization> */
    return $relation;
}

/** `insteadof` on the model picks HasArchivedRevisions::revisions (a DamageReport relation) over HasRevisions'. */
function issue1613_insteadof_trait_resolves_the_pick(Shop $shop): HasMany
{
    $relation = $shop->revisions();
    /** @psalm-check-type-exact $relation = HasMany<DamageReport, Shop> */
    return $relation;
}

/** The pick is made between two nested traits, so the winner is the one reached through the nested trait. */
function issue1613_nested_insteadof_trait_resolves_the_pick(Supplier $supplier): HasMany
{
    $relation = $supplier->revisions();
    /** @psalm-check-type-exact $relation = HasMany<DamageReport, Supplier> */
    return $relation;
}

/** The pick is made inside a composed trait, not on the model. */
function issue1613_insteadof_inside_middle_trait_resolves_the_pick(MechanicSpecialization $specialization): HasMany
{
    $relation = $specialization->revisions();
    /** @psalm-check-type-exact $relation = HasMany<DamageReport, MechanicSpecialization> */
    return $relation;
}

function issue1613_same_trait_two_paths(Part $part): HasMany
{
    $relation = $part->revisions();
    /** @psalm-check-type-exact $relation = HasMany<Part, Part> */
    return $relation;
}
?>
--EXPECTF--
