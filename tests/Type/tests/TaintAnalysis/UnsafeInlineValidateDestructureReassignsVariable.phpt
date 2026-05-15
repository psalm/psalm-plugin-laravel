--ARGS--
--threads=1 --no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Soundness probe: `[$_, $bound] = $src` reassigns `$bound` via list
 * destructuring. The outer `Expr\Assign` node has `Expr\List_` on the
 * LHS (nikic/php-parser's `fixupArrayDestructuring` rewrites both the
 * short-form `[$a, $b] = ...` and the long-form `list($a, $b) = ...` to
 * `Expr\List_`), not a plain `Expr\Variable`. The inner Variables still
 * receive LHS `removeTaints` dispatches from `AssignmentAnalyzer`, so
 * without destructuring-aware eviction in
 * `InlineValidateRulesCollector::beforeExpressionAnalysis`, a stale
 * cache entry for `$bound` (from the inline `validate()` + `input()`
 * pair above) would silently strip header/cookie taint from the raw
 * `$_GET` element on the destructured edge.
 *
 * Both TaintedHeader and TaintedSSRF must fire for the redirect, proving
 * the destructured slot was evicted before the LHS taint event ran.
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
function destructureReassignProbe(Request $request): RedirectResponse {
    $request->validate(['k' => 'email']);
    // Seed the cache with the email-rule escape under the name `$bound`.
    $bound = $request->input('k');

    [$_, $bound] = $_GET;

    return redirect()->to($bound);
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header
TaintedSSRF on line %d: Detected tainted network request
