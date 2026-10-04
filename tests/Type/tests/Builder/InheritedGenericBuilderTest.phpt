--FILE--
<?php declare(strict_types=1);

use App\Builders\InheritedModelBuilder;
use App\Models\CollidingScopeModel;
use App\Models\Customer;
use App\Models\InheritedBuilderChild;
use App\Models\InheritedBuilderModel;
use App\Models\SharedBuilderSoftModel;
use App\Models\SharedBuilderWithScopeModel;
use App\Models\Vehicle;

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
 * The Builder stub declares `count`, so Psalm checks the call's arguments against it before asking
 * the plugin for a return type. A scope hand-off recorded for that call is never consumed and would
 * later replace the params of an unrelated `count('id')` with the descendant scope's `int $limit`.
 */
function stub_declared_method_collision_leaves_no_pending_scope(): void
{
    InheritedBuilderChild::query()->count();

    $_count = Customer::query()->count('id');
    /** @psalm-check-type-exact $_count = int<0, max> */
}

/**
 * Same stale-entry hazard from the base-Builder producer: `CollidingScopeModel::scopeCount()` makes the
 * base provider record a hand-off for `count`, which Psalm never consumes (the stub declares `count`).
 * A later custom-builder call to a like-named method must not inherit the scope's (empty) parameter list,
 * or the named argument below is reported as unknown. (`Vehicle`, not an `InheritedBuilder*` model:
 * those register a `scopeCount` of their own on the shared builder.)
 */
function base_builder_stub_declared_collision_does_not_contaminate_custom_builder(): void
{
    CollidingScopeModel::query()->count();

    $_count = Vehicle::query()->count(columns: 'id');
    /** @psalm-check-type-exact $_count = int<0, max> */
}

/** Base-only variant of the same leak (pre-dates #1620): the stale entry must not reach an unrelated base-Builder call either. */
function base_builder_stub_declared_collision_does_not_contaminate_base_builder(): void
{
    CollidingScopeModel::query()->count();

    $_count = Customer::query()->count(columns: 'id');
    /** @psalm-check-type-exact $_count = int<0, max> */
}

/**
 * `InheritedBuilderChild` declares `scopeCount(int $limit)` on the shared builder. With no pending receiver
 * model (the hand-off is skipped for the stub-declared `count`), the registry fallback must not answer a
 * base receiver's `count(columns: 'id')` with that descendant scope's params: Psalm validates against the
 * stub declaration instead.
 */
function base_receiver_stub_declared_method_ignores_child_scope_params(): void
{
    $_count = InheritedBuilderModel::query()->count(columns: 'id');
    /** @psalm-check-type-exact $_count = int<0, max> */
}

/**
 * One builder shared by a SoftDeletes model and a model with its own `scopeWithTrashed($query, int $mode = 0)`.
 * The trait params provider answers the SoftDeletes call, so the scope hand-off recorded for the scoped
 * model's call must be consumed there too; otherwise it later replaces the params of an unrelated
 * base-Builder `withTrashed(false)` with the scope's `int $mode`.
 */
function trait_params_provider_consumes_pending_scope_handoff(): void
{
    SharedBuilderSoftModel::query()->withTrashed();
    SharedBuilderWithScopeModel::query()->withTrashed(1);

    $_trashed = Customer::query()->withTrashed(false);
    /** @psalm-check-type-exact $_trashed = \Illuminate\Database\Eloquent\Builder<\App\Models\Customer> */
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
?>
--EXPECTF--
