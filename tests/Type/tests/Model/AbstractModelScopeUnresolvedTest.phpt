--FILE--
<?php declare(strict_types=1);

use App\Models\AbstractDocument;
use App\Models\Contract;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/901
 * It documents the existing issue as is, ideally this test should have zero expected Psalm output.
 *
 * Pins the CURRENT (broken) behavior: a scope declared on an ABSTRACT base model is not
 * resolved when the call-site receiver is typed as that abstract base — UndefinedMagicMethod.
 *
 * Root cause: src/Handlers/Eloquent/ModelRegistrationHandler::afterCodebasePopulated() skips
 * every `$storage->abstract` class (the `if ($storage->abstract) { continue; }` guard) before it
 * reaches registerHandlersForModel(), so the per-model instance __call forwarding providers
 * (ModelMethodHandler) are never registered for the abstract base. An abstract base cannot be
 * instantiated, but it CAN be a typed parameter / property, so the instance-forwarding path needs
 * registering for abstract user models too.
 *
 * Autoloadable fixtures are required here: an inline (non-autoloadable) base would instead fail
 * the `class_exists($storage->name, true)` gate further down the same loop and miss registration
 * for an unrelated reason, masking the abstract guard. AbstractDocument is a real abstract fixture;
 * Contract is a concrete child of it.
 *
 * Scope of the repro (narrower than the original filing): only the abstract-typed *instance*
 * receiver regresses. A concrete-typed instance (`$contract->signedBetween(...)`) resolves today
 * and stays green — included below as the contrast that flips together with the fix. The
 * builder/static path (`Contract::query()->signedBetween(...)`) resolves via the global
 * BuilderScopeHandler and is already covered by InstanceScopeCallTest.phpt.
 */

/**
 * BROKEN: receiver typed as the abstract base. Pins UndefinedMagicMethod.
 */
function pin_901_abstract_typed_instance(AbstractDocument $document): void
{
    $document->signedBetween(now(), now());
}

/**
 * Control: concrete child instance resolves today (concrete models register normally).
 * Produces no output; must stay green and is the partner that flips when #901 is fixed.
 */
function pin_901_concrete_typed_instance(Contract $contract): void
{
    $contract->signedBetween(now(), now());
}

?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method App\Models\AbstractDocument::signedbetween does not exist
