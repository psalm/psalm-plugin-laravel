--ARGS--
--threads=1 --no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Soundness probe for #834: when `$reassignBound` was previously bound
 * to `$request->input('k')` (carrying the inline-validate rule's
 * escape), a later reassignment of `$reassignBound` to raw user input
 * must NOT silently benefit from the cached escape. The reassignment
 * severs the binding; the escape applies only to the validated read.
 *
 * Implementation note: the eviction in
 * `InlineValidateRulesCollector::beforeExpressionAnalysis` runs ahead
 * of the AssignmentAnalyzer LHS taint event for the new binding, so
 * the cached escape is gone before the LHS edge is built. Without that
 * eviction ordering, a redirect-style sink would miss the
 * TaintedHeader on the raw bytes — a real security regression.
 *
 * --threads=1: see SafeInlineValidateCustomRuleEscapesHeaderViaVariable
 * for why these tests pin to a single Psalm thread.
 */
/**
 * @psalm-suppress MixedAssignment
 * @psalm-suppress UnusedVariable
 * @psalm-suppress PossiblyUndefinedStringArrayOffset
 * @psalm-suppress PossiblyInvalidArgument
 * @psalm-suppress PossiblyInvalidCast
 */
function reassignToRawInputProbe(Request $request): RedirectResponse {
    $request->validate(['k' => 'email']);

    // First binding populates the per-variable escape cache so the
    // subsequent reassignment exercises the eviction path; the read
    // of $reassignBound below is the second binding's value.
    $reassignBound = $request->input('k');
    $reassignBound = $_POST['raw'];

    return redirect()->to($reassignBound);
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header
TaintedSSRF on line %d: Detected tainted network request
