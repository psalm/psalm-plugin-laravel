--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Negative lock-in: the wildcard-suffix fallback in
 * `ValidationRuleAnalyzer::lookupRuleByKey` is deliberately scoped to
 * purely numeric trailing segments (`/\.\d+$/`). A non-numeric trailing
 * segment (`$request->input('email.foo')`) must NOT strip-and-retry
 * against `'email.*'` rules — the plugin does not model Laravel's full
 * dot-notation wildcard resolution, and applying the escape to arbitrary
 * dotted reads would silently widen taint-escape coverage beyond what the
 * regex can soundly justify.
 *
 * If a future refactor loosens the regex from `\d+` to `\w+` / `[^.]+`,
 * this test flips to the safe expectation and fails, forcing a deliberate
 * review. Pairs with the positive `SafeInlineValidateWildcardArrayRuleEscapesHeaderIndexedAccess`
 * test so the numeric-only boundary is locked from both sides.
 */
/** @psalm-suppress MixedArgument */
function storeWildcardNonNumericKey(Request $request): RedirectResponse {
    $request->validate([
        'email.*' => ['required', Rule::email()],
    ]);

    return redirect()->to($request->input('email.foo'));
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header
TaintedSSRF on line %d: Detected tainted network request
