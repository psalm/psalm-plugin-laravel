--ARGS--
--threads=1 --no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Variable-binding counterpart of SafeInlineValidateReassignmentKnownLimitation.
 *
 * KNOWN LIMITATION: the inline-validate cache is keyed by source-level
 * variable name, so reassigning `$request` to a different (unvalidated)
 * Request object between `validate()` and `input()` does not invalidate
 * the cache. The escape from the original `validate()` continues to
 * apply when the bound local variable is later used at a sink. This
 * locks in the current behaviour so any future tightening (e.g. via a
 * Psalm flow-graph integration) is a deliberate, reviewed change.
 *
 * Documented in InlineValidateRulesCollector class docblock under
 * "Soundness caveats". Prefer a typed FormRequest for security-sensitive
 * paths — the framework-level `ValidatesWhenResolvedTrait` guarantee
 * is not vulnerable to this kind of re-aliasing.
 *
 * --threads=1: see SafeInlineValidateCustomRuleEscapesHeaderViaVariable
 * for why these tests pin to a single Psalm thread.
 */
/**
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArgument
 */
function reassignProbeViaVariable(Request $request): RedirectResponse {
    $request->validate(['contact_email' => 'required|email']);

    // Reassign to a fresh, unvalidated Request instance.
    $request = new Request();

    // Bind the unvalidated read to a local variable. The cache for
    // `$request` was populated under the original Request, and the
    // name-keyed cache cannot tell the two objects apart, so the
    // 'email' escape is still applied to the bound value.
    $boundReassigned = $request->input('contact_email');

    return redirect()->to($boundReassigned);
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
