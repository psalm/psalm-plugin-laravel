--ARGS--
--threads=1 --no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Variable-binding variant exercising the indexed-access lookup path
 * (`$request->input('email.0')`) with a wildcard-suffix rule, then
 * one-hop indirection through a local variable before the sink.
 *
 * The indexed accessor is the case that actually needs the wildcard
 * fallback: `resolveRules` already stores the parent key `'email'` for
 * `'email.*'` patterns, so whole-array access resolves without help.
 * Indexed access (`'email.0'`) must strip the trailing numeric segment
 * and retry — and that fallback must fire in
 * `InlineValidateRulesCollector::resolveEscapeFromAccessorRhs` for the
 * via-variable path to stay in parity with the direct call.
 *
 * TaintedSSRF still fires (DNS resolution of a valid email domain can
 * still hit an internal host). TaintedHeader must not.
 *
 * --threads=1: see SafeInlineValidateCustomRuleEscapesHeaderViaVariable
 * for why the via-variable tests pin to a single Psalm thread.
 */
/**
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArgument
 */
function storeWildcardIndexedViaVariable(Request $request): RedirectResponse {
    $request->validate([
        'email.*' => ['required', Rule::email()],
    ]);

    $boundWildcardEmail = $request->input('email.0');

    return redirect()->to($boundWildcardEmail);
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
