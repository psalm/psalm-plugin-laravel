--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * KNOWN LIMITATION: the inline-validate cache is keyed by source-level
 * variable name. Reassigning `$request` to a different object between
 * validate() and input() is not tracked — the cached escape continues
 * to apply to the new object. This test locks the current behaviour so
 * any future tightening (e.g. via a Psalm flow-graph integration) is a
 * deliberate, reviewed change.
 *
 * Documented in InlineValidateRulesCollector class docblock under
 * "Soundness caveats". Prefer a typed FormRequest for security-sensitive
 * paths — its framework-level validation guarantee is not vulnerable to
 * this kind of re-aliasing.
 */
/** @psalm-suppress MixedArgument */
function reassignProbe(Request $request): RedirectResponse {
    $request->validate(['contact_email' => 'required|email']);

    // Reassign to a fresh, unvalidated Request instance.
    $request = new Request();

    // Analyzer applies the 'email' escape to this read because the cache
    // entry is still keyed under the variable name `$request`. In practice
    // this is a very rare pattern — code that re-creates a Request object
    // by hand is almost exclusively in tests or low-level framework code.
    return redirect()->to($request->input('contact_email'));
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
