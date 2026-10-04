--FILE--
<?php declare(strict_types=1);

use App\Builders\InheritedModelBuilder;
use App\Builders\WorkOrderBuilder;
use App\Models\InheritedBuilderChild;
use App\Models\InheritedBuilderModel;
use App\Models\WorkOrder;

/**
 * Issue #1620 — one generic custom builder shared by a base model and a descendant that inherits
 * `newEloquentBuilder()`. Scope and SoftDeletes calls must resolve from the receiver's model
 * template argument, not from whichever model registered last.
 *
 * Fixtures live in tests/Application/app: inline phpt models are never autoloadable, so they never
 * get custom-builder registration.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1620
 */

/** SoftDeletes trait method on a base receiver keeps the base model. */
function base_trait_method(): void
{
    $_all = InheritedBuilderModel::query()->withTrashed()->get();
    /** @psalm-check-type-exact $_all = \Illuminate\Database\Eloquent\Collection<int, InheritedBuilderModel> */
}

/** Legacy scope on a base receiver keeps the base model. */
function base_scope(): void
{
    $_all = InheritedBuilderModel::query()->visible()->get();
    /** @psalm-check-type-exact $_all = \Illuminate\Database\Eloquent\Collection<int, InheritedBuilderModel> */
}

/** Own `@return static` builder method chained into a trait method. */
function base_custom_method_then_trait_method(): void
{
    $_all = InheritedBuilderModel::query()->active()->withTrashed()->get();
    /** @psalm-check-type-exact $_all = \Illuminate\Database\Eloquent\Collection<int, InheritedBuilderModel> */
}

/** Descendant receiver resolves to the descendant, for scope and trait method alike. */
function child_receiver(): void
{
    $_scope = InheritedBuilderChild::query()->visible();
    /** @psalm-check-type-exact $_scope = \App\Builders\InheritedModelBuilder<InheritedBuilderChild> */

    $_trait = InheritedBuilderChild::query()->withTrashed();
    /** @psalm-check-type-exact $_trait = \App\Builders\InheritedModelBuilder<InheritedBuilderChild> */
}

/** A scope declared only by the descendant must not change what the base receiver's native `count()` returns. */
function base_receiver_ignores_child_only_scope(): void
{
    $_count = InheritedBuilderModel::query()->count();
    /** @psalm-check-type-exact $_count = int<0, max> */
}

/** The descendant's overriding scope signature (extra optional parameter) applies to descendant receivers. */
function child_receiver_uses_overriding_scope_params(): void
{
    $_scope = InheritedBuilderChild::query()->visible(true);
    /** @psalm-check-type-exact $_scope = \App\Builders\InheritedModelBuilder<InheritedBuilderChild> */
}

/**
 * `InheritedBuilderChild` declares `scopeCount(int $limit)` on the shared builder, and the Builder stub
 * declares `count`. The scope params provider must decline for a method the builder declares, so a base
 * receiver's `count(columns: 'id')` validates against the stub rather than the descendant scope's params.
 */
function base_receiver_stub_declared_method_ignores_child_scope_params(): void
{
    $_count = InheritedBuilderModel::query()->count(columns: 'id');
    /** @psalm-check-type-exact $_count = int<0, max> */
}

/**
 * A template-typed receiver resolves through the template's `as` bound: the descendant's overriding
 * scope signature applies, and the returned builder keeps the template instead of collapsing to the bound.
 * `@psalm-check-type-exact` cannot express the printed `T:fn-... as ...` form, so the preserved template
 * is asserted through the declared return type.
 *
 * @template T of InheritedBuilderChild
 * @param InheritedModelBuilder<T> $builder
 * @return InheritedModelBuilder<T>
 */
function template_receiver_bound_to_descendant_scope(InheritedModelBuilder $builder): InheritedModelBuilder
{
    return $builder->visible(true);
}

/**
 * @template T of InheritedBuilderChild
 * @param InheritedModelBuilder<T> $builder
 * @return InheritedModelBuilder<T>
 */
function template_receiver_bound_to_descendant_trait_method(InheritedModelBuilder $builder): InheritedModelBuilder
{
    return $builder->withTrashed();
}

/**
 * @template T of InheritedBuilderModel
 * @param InheritedModelBuilder<T> $builder
 * @return InheritedModelBuilder<T>
 */
function template_receiver_bound_to_base_scope(InheritedModelBuilder $builder): InheritedModelBuilder
{
    return $builder->visible();
}

/**
 * @template T of InheritedBuilderModel
 * @param InheritedModelBuilder<T> $builder
 * @return InheritedModelBuilder<T>
 */
function template_receiver_bound_to_base_trait_method(InheritedModelBuilder $builder): InheritedModelBuilder
{
    return $builder->withTrashed();
}

/**
 * Return providers fire per receiver atomic, but the receiver expression's type is the whole union. The
 * provider answering for `WorkOrderBuilder<WorkOrder>` must not read the template-typed sibling arm and
 * fabricate `WorkOrderBuilder<T>` from it. As above, the result is asserted through the declared return type.
 *
 * @template T of InheritedBuilderChild
 * @param InheritedModelBuilder<T>|WorkOrderBuilder<WorkOrder> $builder
 * @return InheritedModelBuilder<T>|WorkOrderBuilder<WorkOrder>
 */
function union_receiver_does_not_borrow_sibling_arm_template(
    InheritedModelBuilder|WorkOrderBuilder $builder,
): InheritedModelBuilder|WorkOrderBuilder {
    return $builder->withTrashed();
}
?>
--EXPECTF--
