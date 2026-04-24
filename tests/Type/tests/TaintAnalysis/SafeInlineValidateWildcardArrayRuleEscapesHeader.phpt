--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Wildcard-suffix rule (`'email.*' => [..., Rule::email()]`) constrains each
 * element of an array-shaped field. Reading the whole field via
 * `$request->input('email')` must inherit the element rule's header/cookie
 * escape — bulk-input endpoints (mass invite, address-book import, tag
 * arrays) are exactly the surface where this pattern appears in the wild.
 *
 * Note: this test does NOT exercise the new `lookupRuleByKey` numeric-suffix
 * fallback; it exercises the existing `resolveRules` wildcard-to-parent
 * expansion — `$wildcardDirect` stores the element rule under the parent
 * key `'email'` so the exact-key lookup already hits. Included as a
 * regression guard: a future refactor that stops aggregating the element's
 * `removedTaints` into the parent entry would silently regress this case.
 * The `.0`-suffix counterpart (`SafeInlineValidateWildcardArrayRuleEscapesHeaderIndexedAccess`)
 * is what exercises the new fallback.
 *
 * TaintedSSRF still fires: a validated email's domain may still resolve to
 * an internal host. Mirrors the scalar `SafeInlineValidateRuleEmailEscapesHeader`.
 */
/** @psalm-suppress MixedArgument */
function storeWildcardWhole(Request $request): RedirectResponse {
    $request->validate([
        'email.*' => ['required', Rule::email()],
    ]);

    return redirect()->to($request->input('email'));
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
