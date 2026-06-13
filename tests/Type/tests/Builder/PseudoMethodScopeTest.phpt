--FILE--
<?php declare(strict_types=1);

use App\Models\PseudoScopeModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scopes declared ONLY as a class-level `@method scopeXxx()` PHPDoc tag — no concrete scopeXxx()
 * body and no #[Scope] method — resolve on a Builder, exactly like a real legacy scope.
 * BuilderScopeHandler::getScopeParams consults the model's pseudo_static_methods / pseudo_methods
 * as a final fallback (after a real scopeXxx() and a #[Scope] method), strips the leading $query a
 * real scope receives, and fabricates Builder<TModel>. This trusts the tag (Larastan parity).
 *
 * NOTE: a tag with no backing real scopeXxx()/#[Scope] is NOT runtime-dispatchable
 * (Model::hasNamedScope is method_exists-gated, so the bare call throws BadMethodCallException);
 * the plugin types it optimistically by trusting the developer's @method assertion. This suite
 * pins the static-analysis behaviour, not a runnable scope.
 *
 * Psalm flattens a model's tags from three sources into its local pseudo maps, all covered here:
 * direct tags, a `use`d trait (the issue's package/trait shape), and an abstract parent.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1054
 */

/** Zero-arg pseudo-scope (the issue's exact shape) resolves to the builder. */
function test_pseudo_scope_resolves(): void
{
    $_result = PseudoScopeModel::query()->publishedDoc();
    /** @psalm-check-type-exact $_result = Builder<PseudoScopeModel> */
}

/**
 * A scope documented as a NON-static `@method` tag (stored in pseudo_methods, not
 * pseudo_static_methods) resolves through the same fallback — the plugin consults both maps.
 */
function test_pseudo_scope_instance_method_tag_resolves(): void
{
    $_result = PseudoScopeModel::query()->instanceDoc();
    /** @psalm-check-type-exact $_result = Builder<PseudoScopeModel> */
}

/**
 * The literal #1054 shape: a pseudo-scope hosted on a `use`d trait (HasDocblockScopes), merged
 * into the model's local pseudo maps by Populator::populateDataFromTrait. The trait tag's raw
 * `self $owner` param (after the stripped $query) expands to the using model, so a model instance
 * is accepted.
 */
function test_pseudo_scope_from_trait_resolves(PseudoScopeModel $owner): void
{
    $_result = PseudoScopeModel::query()->fromTraitDoc($owner);
    /** @psalm-check-type-exact $_result = Builder<PseudoScopeModel> */
}

/**
 * A pseudo-scope declared on the ABSTRACT parent (AbstractPseudoScopeBase), inherited via
 * Populator::populateDataFromParentClass copying the tag into the child's local pseudo maps.
 */
function test_pseudo_scope_from_parent_resolves(): void
{
    $_result = PseudoScopeModel::query()->fromParentDoc();
    /** @psalm-check-type-exact $_result = Builder<PseudoScopeModel> */
}

/** Optional post-$query param may be omitted; a variadic tail accepts any number of values. */
function test_pseudo_scope_optional_and_variadic(): void
{
    $_omitted = PseudoScopeModel::query()->optionalVariadicDoc();
    /** @psalm-check-type-exact $_omitted = Builder<PseudoScopeModel> */

    $_variadic = PseudoScopeModel::query()->optionalVariadicDoc('a', 'b', 'c');
    /** @psalm-check-type-exact $_variadic = Builder<PseudoScopeModel> */
}

/** Pseudo-scope with a param after $query: the post-$query value is accepted (strip). */
function test_pseudo_scope_with_arg(): void
{
    $_result = PseudoScopeModel::query()->ofTypeDoc('post');
    /** @psalm-check-type-exact $_result = Builder<PseudoScopeModel> */
}

/** Negative: the argument is type-checked against the scope's declared param (minus $query). */
function test_pseudo_scope_arg_type_checked(): void
{
    PseudoScopeModel::query()->ofTypeDoc(123);
}

/** Negative: too few args — the required $type after $query is missing. */
function test_pseudo_scope_too_few_args(): void
{
    PseudoScopeModel::query()->ofTypeDoc();
}

/** Negative: too many args — only one post-$query param ($type) is declared. */
function test_pseudo_scope_too_many_args(): void
{
    PseudoScopeModel::query()->ofTypeDoc('a', 'b');
}

/** Negative: the trait scope's `self $owner` expanded to the model, so a non-model is rejected. */
function test_pseudo_scope_trait_self_param_rejects_non_model(): void
{
    PseudoScopeModel::query()->fromTraitDoc('not-a-model');
}

/** Negative: the optional param is still type-checked when supplied. */
function test_pseudo_scope_optional_arg_type_checked(): void
{
    PseudoScopeModel::query()->optionalVariadicDoc(123);
}

/** Negative: the variadic TAIL element type is enforced too (not silently widened to mixed). */
function test_pseudo_scope_variadic_element_type_checked(): void
{
    PseudoScopeModel::query()->optionalVariadicDoc('ok', 123);
}

/**
 * The fix also flows through the STATIC model form (Model::publishedDoc()), via the per-model
 * existence/params/return providers (ModelMethodHandler) which share getScopeParams/hasScopeMethod.
 * The fixture is autoloadable, so it is registered. Same stripped-$query convention, so the
 * post-$query argument is still checked. (Runtime note: this form also throws BadMethodCallException
 * for a tag-only scope; the plugin types it by trusting the tag.)
 */
function test_pseudo_scope_static_model_form(): void
{
    $_result = PseudoScopeModel::publishedDoc();
    /** @psalm-check-type-exact $_result = Builder<PseudoScopeModel> */
}

/** Negative on the static form: the post-$query argument is type-checked there as well. */
function test_pseudo_scope_static_arg_type_checked(): void
{
    PseudoScopeModel::ofTypeDoc(123);
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::oftypedoc expects string, but 123 provided
TooFewArguments on line %d: Too few arguments for Illuminate\Database\Eloquent\Builder::oftypedoc - expecting type to be passed
TooManyArguments on line %d: Too many arguments for Illuminate\Database\Eloquent\Builder::oftypedoc - expecting 1 but saw 2
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::fromtraitdoc expects App\Models\PseudoScopeModel, but 'not-a-model' provided
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::optionalvariadicdoc expects string, but 123 provided
InvalidArgument on line %d: Argument 2 of Illuminate\Database\Eloquent\Builder::optionalvariadicdoc expects string, but 123 provided
InvalidArgument on line %d: Argument 1 of App\Models\PseudoScopeModel::oftypedoc expects string, but 123 provided
