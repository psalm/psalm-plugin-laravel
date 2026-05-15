--ARGS--
--threads=1 --no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Soundness probe: `$v = &$other` is `Expr\AssignRef`, not `Expr\Assign`.
 * Psalm's reference handling does not route through
 * `AssignmentAnalyzer::analyze`, so the LHS taint event for the new
 * binding is never dispatched. But subsequent reads of `$v` still hit
 * the Variable branch in `ValidationTaintHandler::removeTaints`, and a
 * stale cached escape would silently strip header / cookie taint from
 * the raw reference target. The fix evicts on `AssignRef` in
 * `InlineValidateRulesCollector::beforeExpressionAnalysis`.
 *
 * Both TaintedHeader and TaintedSSRF must fire for the redirect — that
 * proves the rebound slot was evicted before the read-side dispatch.
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
function assignRefReassignProbe(Request $request): RedirectResponse {
    $request->validate(['k' => 'email']);
    // Seed the cache with the email-rule escape under the name `$assignRefBound`.
    $assignRefBound = $request->input('k');

    $raw = $_GET['raw'];
    $assignRefBound = &$raw;

    return redirect()->to($assignRefBound);
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header
TaintedSSRF on line %d: Detected tainted network request
