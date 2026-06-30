--FILE--
<?php declare(strict_types=1);

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Models\Receipt;

/**
 * Trait-hosted #[Scope]-attributed methods must resolve on all four call surfaces:
 * base-builder instance, custom-builder instance, static model call, and relation chain.
 *
 * Before the fix, hasScopeAttribute() called getStorage() with the using-class method
 * identifier. For trait-hosted methods Psalm stores attribute metadata only on the DECLARING
 * class (the trait), so getStorage() on the using class returned a stub without attributes,
 * hasScopeAttribute() returned false, and the scope went undetected — surfacing as
 * UndefinedMagicMethod on custom builders and as an unchecked call on the base builder.
 *
 * The fix resolves through getDeclaringMethodId() first so the trait's MethodStorage
 * (which carries the #[Scope] attribute) is always consulted.
 *
 * Archetypes:
 *  - Contract (base Builder, uses HasFlaggedScope directly) — base-builder + static surfaces
 *  - Vehicle (custom VehicleBuilder, uses HasFlaggedScope) — custom-builder + relation surfaces
 *  - WorkOrder (custom WorkOrderBuilder, uses ComparesRank) — attributed scope with self param
 *  - Contract/Receipt (AbstractDocument children, ComparesRank on parent) — sibling-acceptance
 *    proving self-pin (#1043) applies to attributed scopes the same way as legacy ones
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1046
 */

/* -- Base-builder instance call (Contract::query()) ---------------------------------------- */

/** Positive: trait-hosted #[Scope] resolves on the base Builder. */
function test_base_builder_instance_call(): void
{
    $_result = Contract::query()->active();
    /** @psalm-check-type-exact $_result = Illuminate\Database\Eloquent\Builder<App\Models\Contract> */
}

/** Positive: legacy trait scope still resolves (regression guard). */
function test_base_builder_legacy_trait_scope(): void
{
    $_result = Contract::query()->flagged();
    /** @psalm-check-type-exact $_result = Illuminate\Database\Eloquent\Builder<App\Models\Contract> */
}

/* -- Custom-builder instance call (Vehicle::query()) --------------------------------------- */

/** Positive: trait-hosted #[Scope] resolves on a custom Builder subclass. */
function test_custom_builder_instance_call(): void
{
    $_result = Vehicle::query()->active();
    /** @psalm-check-type-exact $_result = App\Builders\VehicleBuilder<App\Models\Vehicle> */
}

/** Positive: legacy trait scope on custom builder still resolves (regression guard). */
function test_custom_builder_legacy_trait_scope(): void
{
    $_result = Vehicle::query()->flagged();
    /** @psalm-check-type-exact $_result = App\Builders\VehicleBuilder<App\Models\Vehicle> */
}

/* -- Static model call (Model::scope()) ---------------------------------------------------- */

/**
 * Known limitation: the static call is runtime-valid (protected scope → __callStatic →
 * static::query()->active()), but Psalm's MethodAnalyzer::checkStatic flags any static
 * call to a non-static method without considering accessibility + __callStatic — a false
 * positive blocked on https://github.com/vimeo/psalm/issues/11876. Same behavior as
 * class-hosted protected #[Scope] methods (see StaticBuilderMethodsTest.phpt). The
 * InvalidStaticInvocation expectations below flip when the upstream fix lands.
 */
function test_static_model_call(): void
{
    Contract::active();
}

/** Same limitation on a model with a custom builder. */
function test_static_model_call_custom_builder(): void
{
    Vehicle::active();
}

/* -- Relation chain ($model->relation()->scope()) ------------------------------------------ */

/**
 * Positive: trait-hosted #[Scope] resolves on a HasMany relation query. The relation
 * type is preserved (Relation::forwardDecoratedCallTo returns the relation for
 * builder-returning forwards), matching runtime chaining semantics.
 */
function test_relation_chain(Customer $customer): void
{
    $_result = $customer->vehicles()->active();
    /** @psalm-check-type-exact $_result = Illuminate\Database\Eloquent\Relations\HasMany<App\Models\Vehicle, App\Models\Customer> */
}

/* -- #[Scope] with self param: self-pin applies the same as for legacy scopes -------------- */

/**
 * Positive: attributed scope with a `self`-typed param resolves on the custom builder.
 * `self` binds to the composing class (WorkOrder), so the model itself is accepted.
 */
function test_attributed_scope_self_param_accepts_model(WorkOrder $workOrder): void
{
    $_result = WorkOrder::query()->outranks($workOrder);
    /** @psalm-check-type-exact $_result = App\Builders\WorkOrderBuilder<App\Models\WorkOrder> */
}

/** Negative: non-model arg rejected — verifies argument-type checking works for attributed scopes. */
function test_attributed_scope_self_param_is_type_checked(): void
{
    WorkOrder::query()->outranks('not a model');
}

/**
 * Positive: on a base-Builder model whose parent composes the trait, `self` binds to the
 * composing PARENT (AbstractDocument), so a sibling child (Receipt) is accepted.
 * Mirrors the legacy-scope sibling-acceptance case from Issue1031TraitScopeSelfParamTest.
 */
function test_attributed_scope_sibling_accepted(Receipt $receipt): void
{
    $_result = Contract::query()->outranks($receipt);
    /** @psalm-check-type-exact $_result = Illuminate\Database\Eloquent\Builder<App\Models\Contract> */
}

/** Negative: non-model is rejected even in the parent-composing case. */
function test_attributed_scope_sibling_non_model_rejected(): void
{
    Contract::query()->outranks('not a model');
}
?>
--EXPECTF--
InvalidStaticInvocation on line %d: Method App\Models\Concerns\HasFlaggedScope::active is not static, but is called statically
InvalidStaticInvocation on line %d: Method App\Models\Concerns\HasFlaggedScope::active is not static, but is called statically
InvalidArgument on line %d: Argument 1 of App\Builders\WorkOrderBuilder::outranks expects App\Models\WorkOrder, but 'not a model' provided
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::outranks expects App\Models\AbstractDocument, but 'not a model' provided
