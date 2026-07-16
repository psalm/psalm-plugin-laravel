--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// Skip on Laravel < 12: this test asserts #[Scope]-attributed scope resolution.
// The #[Scope] attribute is Laravel 12+, so on Laravel 11 the plugin correctly does
// not resolve such methods as scopes (see EloquentModelMethods::hasScopeAttribute).
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('12.0.0');
--FILE--
<?php declare(strict_types=1);

use App\Models\AbstractDocument;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Builder;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/901
 *
 * Asserts the fix for #901: scopes and forwarded builder methods now resolve on a receiver typed
 * as an ABSTRACT base model, not only on concrete children. This file replaces the former
 * AbstractModelScopeUnresolvedTest, which pinned the bug (UndefinedMagicMethod) before the fix.
 *
 * Before the fix, ModelRegistrationHandler::afterCodebasePopulated() skipped every abstract class
 * wholesale, so the per-model method-forwarding providers ({@see ModelMethodHandler}) were never
 * registered for an abstract base — an abstract-typed instance call fell through to
 * UndefinedMagicMethod. Registration is now split: the storage-based forwarding providers register
 * for abstract bases too, while the instantiation-based property/cast handlers stay concrete-only
 * (an abstract class cannot be instantiated). See ModelRegistrationHandler.
 *
 * Autoloadable fixtures are required: an inline (non-autoloadable) abstract base would instead
 * fail the class_exists() gate in the same loop and miss registration for an unrelated reason,
 * masking the abstract path. AbstractDocument is a real abstract fixture; Contract is a concrete
 * child of it.
 */

/**
 * Core #901: a LEGACY scope (scopeSignedBetween) declared on the abstract base resolves on an
 * abstract-typed receiver. Previously UndefinedMagicMethod.
 */
function legacy_scope_on_abstract_typed_instance(AbstractDocument $document): void
{
    $_result = $document->signedBetween(now(), now());
    /** @psalm-check-type-exact $_result = Builder<AbstractDocument> */
}

/**
 * Concrete-child control: resolves to Builder<Contract>. The distinct generic parameter confirms
 * per-model resolution (not a single shared abstract handler). Was already green before the fix
 * and stays green — the partner that flipped together with the abstract case above.
 */
function legacy_scope_on_concrete_child_instance(Contract $contract): void
{
    $_result = $contract->signedBetween(now(), now());
    /** @psalm-check-type-exact $_result = Builder<Contract> */
}

/**
 * A legacy scope whose extra parameter is typed `self` (scopeSupersedes) resolves on an
 * abstract-typed receiver, and `self` binds to the composing class (AbstractDocument), so an
 * abstract-typed argument is accepted.
 */
function legacy_self_param_scope_on_abstract_typed_instance(AbstractDocument $document): void
{
    $_result = $document->supersedes($document);
    /** @psalm-check-type-exact $_result = Builder<AbstractDocument> */
}

/**
 * A Query\Builder method reachable only via forwarding (whereIn is not declared on
 * Eloquent\Builder) resolves on an abstract-typed receiver too. The &static intersection is the
 * existing forwarding shape (see Builder/StaticBuilderMethodsTest), unchanged by this fix.
 */
function forwarded_query_builder_method_on_abstract_typed_instance(AbstractDocument $document): void
{
    $_result = $document->whereIn('id', [1, 2]);
    /** @psalm-check-type-exact $_result = Builder<AbstractDocument>&static */
}

/**
 * A #[Scope]-attributed method (ComparesRank::outranks, protected) is now RECOGNIZED on an
 * abstract-typed receiver — its return type resolves to Builder<AbstractDocument>. The
 * TooFewArguments below is the documented protected-#[Scope]-on-instance limitation (pinned in
 * Builder/ProtectedScopeSurfacesTest): at runtime PHP forwards the inaccessible call to
 * newQuery()->outranks() with an injected $query, but Psalm checks the real
 * outranks(Builder $query, self $model) signature and reports the missing $query. The point here
 * is parity — the abstract-typed receiver behaves exactly like the concrete child below; before
 * the fix it was UndefinedMagicMethod instead.
 */
function attributed_scope_on_abstract_typed_instance(AbstractDocument $document): void
{
    $_result = $document->outranks($document);
    /** @psalm-check-type-exact $_result = Builder<AbstractDocument> */
}

/** Concrete-child parity for the #[Scope] case: identical TooFewArguments, Builder<Contract>. */
function attributed_scope_on_concrete_child_instance(Contract $contract): void
{
    $_result = $contract->outranks($contract);
    /** @psalm-check-type-exact $_result = Builder<Contract> */
}

/**
 * The accessor handler is storage-based (no instantiation), so it registers for the abstract base
 * too: a legacy accessor declared on the abstract base (getReferenceCodeAttribute) resolves on an
 * abstract-typed receiver. This is the property-shaped twin of the scope fix — before #901 the
 * abstract base was skipped wholesale, so this was UndefinedMagicPropertyFetch.
 */
function inherited_accessor_resolves_on_abstract_typed_instance(AbstractDocument $document): void
{
    $_result = $document->reference_code;
    /** @psalm-check-type-exact $_result = string */
}

/**
 * Concrete-child control for the accessor: the inherited accessor resolves on a concrete child too.
 * AbstractDocument also declares a cast — the registration must skip the (instantiation-based)
 * migration column/cast handler for the abstract base, not throw, while concrete children keep it.
 */
function inherited_accessor_resolves_on_concrete_child(Contract $contract): void
{
    $_result = $contract->reference_code;
    /** @psalm-check-type-exact $_result = string */
}

/**
 * camelCase access to the inherited legacy accessor resolves identically: Laravel's case-insensitive
 * Str::studly resolution treats $contract->referenceCode and $contract->reference_code as the same
 * getReferenceCodeAttribute() accessor. The registry keys it snake_case and the handler normalizes
 * the lookup the same way, so the camelCase spelling is not a false UndefinedMagicPropertyFetch.
 */
function inherited_accessor_resolves_via_camelcase(Contract $contract): void
{
    $_result = $contract->referenceCode;
    /** @psalm-check-type-exact $_result = string */
}
?>
--EXPECTF--
TooFewArguments on line %d: Too few arguments for method App\Models\Concerns\ComparesRank::outranks saw 1
TooFewArguments on line %d: Too few arguments for method App\Models\Concerns\ComparesRank::outranks saw 1
