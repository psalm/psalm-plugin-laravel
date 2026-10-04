--FILE--
<?php declare(strict_types=1);

use App\Models\AbstractDocument;
use App\Models\Contract;
use App\Models\Mechanic;
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
?>
--EXPECTF--
