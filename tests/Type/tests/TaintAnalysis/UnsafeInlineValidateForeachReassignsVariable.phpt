--ARGS--
--threads=1 --no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Soundness probe: a `foreach (... as $bound)` reassigns the loop
 * variable without going through `Expr\Assign`, so the
 * `BeforeExpressionAnalysis` event never fires for the binding. Without
 * the foreach-aware eviction in
 * `InlineValidateRulesCollector::beforeStatementAnalysis`, a previously
 * cached escape on `$bound` (from the inline `validate()` + `input()`
 * pair above) would silently strip header/cookie taint from the raw
 * `$_GET` element on the loop-variable edge.
 *
 * Both TaintedHeader and TaintedSSRF must fire for the redirect — that
 * proves the loop-variable cache was correctly evicted before
 * `AssignmentAnalyzer` dispatched the LHS taint event for the binding.
 *
 * --threads=1: see SafeInlineValidateCustomRuleEscapesHeaderViaVariable
 * for why these tests pin to a single Psalm thread.
 */
/**
 * @psalm-suppress MixedAssignment
 * @psalm-suppress UnusedVariable
 * @psalm-suppress PossiblyInvalidArgument
 * @psalm-suppress PossiblyInvalidCast
 */
function foreachReassignProbe(Request $request): RedirectResponse {
    $request->validate(['k' => 'email']);
    // Seed the cache with the email-rule escape under the name `$bound`.
    $bound = $request->input('k');

    foreach ($_GET as $bound) {
        return redirect()->to($bound);
    }

    return redirect()->to('/');
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header
TaintedSSRF on line %d: Detected tainted network request
