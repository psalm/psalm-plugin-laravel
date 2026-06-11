--FILE--
<?php declare(strict_types=1);

use App\Models\Contract;
use App\Models\Mechanic;
use App\Models\WorkOrder;

/**
 * Issue #1031: a scope declared in a trait with a `self`/`static`-typed non-query
 * parameter must resolve `self`/`static` to the model — not to the class the scope
 * params provider is registered on, and not to a stricter `Model&static` form that
 * would reject a plain model argument on the accept side.
 *
 * Both archetypes use the ComparesRank trait, whose scopes declare self/static params:
 *  - WorkOrder has a custom builder (WorkOrderBuilder), so its params provider registers
 *    on the builder class. Before the fix `self` expanded to WorkOrderBuilder.
 *  - Contract has no custom builder, so its params provider registers on the base
 *    Illuminate Builder. The same misexpansion is possible there.
 *
 * The three callers that share getScopeParams() are all exercised: instance builder
 * (Model::query()->scope()), static model call (Model::scope()), and relation chain
 * ($model->relation()->scope()).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1031
 */

/* -- Custom builder, instance call (WorkOrder::query()) ---------------------- */

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

/* -- Base builder (Contract, no custom builder) ----------------------------- */

/** Positive: on the base Builder the `self` param resolves to Contract. */
function test_base_builder_self_param_accepts_model(Contract $contract): void
{
    $_result = Contract::query()->rankedAbove($contract);
    /** @psalm-check-type-exact $_result = Illuminate\Database\Eloquent\Builder<App\Models\Contract> */
}

/** Negative: the `self` param resolves to Contract, so a non-model arg is rejected. */
function test_base_builder_self_param_is_type_checked(): void
{
    Contract::query()->rankedAbove('not a model');
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of App\Builders\WorkOrderBuilder::rankedabove expects App\Models\WorkOrder, but 'not a model' provided
InvalidArgument on line %d: Argument 1 of App\Builders\WorkOrderBuilder::rankedbelow expects App\Models\WorkOrder, but 'not a model' provided
InvalidArgument on line %d: Argument 1 of App\Builders\WorkOrderBuilder::rankedamong expects list<App\Models\WorkOrder>, but list{'not a model'} provided
InvalidArgument on line %d: Argument 1 of App\Models\WorkOrder::rankedabove expects App\Models\WorkOrder, but 'not a model' provided
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Relations\HasMany::rankedabove expects App\Models\WorkOrder, but 'not a model' provided
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::rankedabove expects App\Models\Contract, but 'not a model' provided
