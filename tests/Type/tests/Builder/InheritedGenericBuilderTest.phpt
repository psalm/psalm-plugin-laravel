--FILE--
<?php declare(strict_types=1);

use App\Models\InheritedBuilderChild;
use App\Models\InheritedBuilderModel;

/**
 * Issue #1620 — one generic custom builder shared by a base model and a descendant that inherits
 * `newEloquentBuilder()`. Scope and SoftDeletes return types must come from the receiver's model,
 * not from whichever model registered last.
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

/** A scope declared only by the descendant works on a descendant receiver. */
function child_only_scope_on_child_receiver(): void
{
    $_scope = InheritedBuilderChild::query()->childOnly();
    /** @psalm-check-type-exact $_scope = \App\Builders\InheritedModelBuilder<InheritedBuilderChild> */
}

/** A resolved base receiver lacking the method declines instead of borrowing the descendant's model, so Psalm reports the magic call. */
function base_receiver_declines_child_only_scope(): void
{
    InheritedBuilderModel::query()->childOnly();
}
?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method App\Builders\InheritedModelBuilder::childonly does not exist
