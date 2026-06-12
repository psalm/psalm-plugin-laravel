--FILE--
<?php declare(strict_types=1);

use App\Models\Contract;
use App\Models\Mechanic;
use App\Models\Receipt;
use App\Models\WorkOrder;

/**
 * Issue #1031: a scope declared in a trait (or on an abstract parent) with a
 * `self`/`static`-typed non-query parameter must resolve those references the way PHP does at
 * runtime — not to the class the scope params provider happens to be registered on.
 *
 *  - `self` binds to the scope's *composing* class (the class that uses the trait, or the
 *    parent that declares the scope), fixed at composition time. Querying a child subclass does
 *    NOT narrow `self` to that child, so a *sibling* child is a valid argument.
 *  - `static`/`$this` bind to the queried model (late static binding), so they DO narrow to the
 *    subclass the scope is invoked on — and a sibling child is rejected.
 *
 * Archetypes:
 *  - WorkOrder composes ComparesRank DIRECTLY and has a custom builder (WorkOrderBuilder), so its
 *    params provider registers on the builder class and `self` == WorkOrder (composing ==
 *    queried). This block is the directly-composed control: unaffected by the parent-hosted
 *    resolution and byte-identical to the original fix.
 *  - Contract has NO custom builder (params provider on the base Illuminate Builder) and inherits
 *    ComparesRank from its abstract parent AbstractDocument, so `self` == the parent. Receipt is a
 *    sibling child used to prove the sibling argument is accepted.
 *
 * The three callers that share getScopeParams() are all exercised: instance builder
 * (Model::query()->scope()), static model call (Model::scope()), and relation chain
 * ($model->relation()->scope()).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1031
 */

/* -- Custom builder, instance call (WorkOrder::query()) — directly-composed control --------- */

/** Positive: native `self` param accepts the model; result keeps the custom builder type. */
function test_self_param_accepts_model(WorkOrder $workOrder): void
{
    $_result = WorkOrder::query()->rankedAbove($workOrder);
    /** @psalm-check-type-exact $_result = App\Builders\WorkOrderBuilder<App\Models\WorkOrder> */
}

/** Negative: the `self` param resolves to WorkOrder, so a non-model arg is rejected. */
function test_self_param_is_type_checked(): void
{
    WorkOrder::query()->rankedAbove('not a model');
}

/** Positive: docblock-only `static` param accepts a plain model (no `&static` over-narrowing). */
function test_static_param_accepts_model(WorkOrder $workOrder): void
{
    $_result = WorkOrder::query()->rankedBelow($workOrder);
    /** @psalm-check-type-exact $_result = App\Builders\WorkOrderBuilder<App\Models\WorkOrder> */
}

/** Negative: `static` resolves to the model, so a non-model arg is rejected. */
function test_static_param_is_type_checked(): void
{
    WorkOrder::query()->rankedBelow('not a model');
}

/** Positive: `self` nested in a generic (list<self>) accepts a list of models. */
function test_nested_generic_param_accepts_models(WorkOrder $workOrder): void
{
    $_result = WorkOrder::query()->rankedAmong([$workOrder]);
    /** @psalm-check-type-exact $_result = App\Builders\WorkOrderBuilder<App\Models\WorkOrder> */
}

/** Negative: list<self> expands to list<WorkOrder>, not list<Builder>. */
function test_nested_generic_param_is_type_checked(): void
{
    WorkOrder::query()->rankedAmong(['not a model']);
}

/* -- Custom builder, static model call (WorkOrder::scope()) ------------------ */

/** Positive: the static model-call path resolves the `self` param to the model. */
function test_static_model_call_accepts_model(WorkOrder $workOrder): void
{
    WorkOrder::rankedAbove($workOrder);
}

/** Negative: the static model-call path still type-checks the `self` param. */
function test_static_model_call_is_type_checked(): void
{
    WorkOrder::rankedAbove('not a model');
}

/* -- Custom builder, relation chain ($model->relation()->scope()) ------------ */

/** Positive: scope on a relation query resolves the `self` param to the related model. */
function test_relation_chain_accepts_model(Mechanic $mechanic, WorkOrder $workOrder): void
{
    $mechanic->workOrders()->rankedAbove($workOrder);
}

/** Negative: scope on a relation query still type-checks the `self` param. */
function test_relation_chain_is_type_checked(Mechanic $mechanic): void
{
    $mechanic->workOrders()->rankedAbove('not a model');
}

/* -- Base builder, trait composed on the PARENT (Contract : AbstractDocument) -------------- */

/**
 * Positive: `self` resolves to the composing parent (AbstractDocument), which the queried child
 * Contract satisfies. Return type stays Builder<Contract> (keyed on the queried model).
 */
function test_base_builder_self_param_accepts_model(Contract $contract): void
{
    $_result = Contract::query()->rankedAbove($contract);
    /** @psalm-check-type-exact $_result = Illuminate\Database\Eloquent\Builder<App\Models\Contract> */
}

/**
 * Positive (the #1031 fix): a SIBLING child is accepted because the trait's `self` binds to the
 * composing parent, not the queried child. Before the fix `self` pinned to Contract and this
 * raised a false InvalidArgument.
 */
function test_base_builder_self_param_accepts_sibling(Receipt $receipt): void
{
    $_result = Contract::query()->rankedAbove($receipt);
    /** @psalm-check-type-exact $_result = Illuminate\Database\Eloquent\Builder<App\Models\Contract> */
}

/**
 * Negative: the `self` param resolves to the composing parent, so a non-model arg is rejected.
 * The expected class is AbstractDocument (the trait's composing class), NOT the queried Contract
 * — this is the observable message change of the fix.
 */
function test_base_builder_self_param_is_type_checked(): void
{
    Contract::query()->rankedAbove('not a model');
}

/**
 * Static-arm control, positive: `static` binds to the queried model (Contract), which accepts a
 * Contract instance.
 */
function test_base_builder_static_param_accepts_model(Contract $contract): void
{
    $_result = Contract::query()->rankedBelow($contract);
    /** @psalm-check-type-exact $_result = Illuminate\Database\Eloquent\Builder<App\Models\Contract> */
}

/**
 * Static-arm control, negative (discriminator): `static` narrows to the queried Contract via late
 * static binding, so a SIBLING child is REJECTED — proving `self` (parent) and `static` (queried
 * model) now resolve differently.
 */
function test_base_builder_static_param_rejects_sibling(Receipt $receipt): void
{
    Contract::query()->rankedBelow($receipt);
}

/**
 * Variant control: a `self`-typed param on a scope declared DIRECTLY on the abstract parent
 * (scopeSupersedes, not via a trait) resolves `self` to AbstractDocument at scan time. The
 * handler's re-expansion is idempotent, so the sibling child is accepted here too.
 */
function test_directly_declared_parent_self_accepts_sibling(Receipt $receipt): void
{
    $_result = Contract::query()->supersedes($receipt);
    /** @psalm-check-type-exact $_result = Illuminate\Database\Eloquent\Builder<App\Models\Contract> */
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of App\Builders\WorkOrderBuilder::rankedabove expects App\Models\WorkOrder, but 'not a model' provided
InvalidArgument on line %d: Argument 1 of App\Builders\WorkOrderBuilder::rankedbelow expects App\Models\WorkOrder, but 'not a model' provided
InvalidArgument on line %d: Argument 1 of App\Builders\WorkOrderBuilder::rankedamong expects list<App\Models\WorkOrder>, but list{'not a model'} provided
InvalidArgument on line %d: Argument 1 of App\Models\WorkOrder::rankedabove expects App\Models\WorkOrder, but 'not a model' provided
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Relations\HasMany::rankedabove expects App\Models\WorkOrder, but 'not a model' provided
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::rankedabove expects App\Models\AbstractDocument, but 'not a model' provided
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::rankedbelow expects App\Models\Contract, but App\Models\Receipt provided
