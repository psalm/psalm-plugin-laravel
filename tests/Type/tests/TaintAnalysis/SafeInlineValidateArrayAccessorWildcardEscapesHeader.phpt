--ARGS--
--threads=1 --no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Wildcard rule (`'email.*' => 'email'`) plus the array accessor
 * (`$request->array('email')`) iterated by `foreach`. Bulk-input
 * endpoints (mass invite, address-book import, tag arrays) read
 * collections via `array()` instead of `input()` — the rule's escape
 * must apply to those reads too (issue #840).
 *
 * Direct foreach over the call result needs the loop variable's escape
 * cache to be populated explicitly: Psalm's `arrayvalue-fetch` builds an
 * edge from the source declaration to the element, bypassing the
 * `removeTaints` mask applied to the call expression. The
 * `beforeStatementAnalysis` foreach hook in
 * `InlineValidateRulesCollector` handles that.
 *
 * TaintedSSRF still fires: a validated email's domain may resolve to an
 * internal host. TaintedHeader must not — that is the entire point of
 * the rule's escape.
 *
 * --threads=1: see SafeInlineValidateCustomRuleEscapesHeaderViaVariable
 * for why these tests pin to a single Psalm thread.
 */
/**
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArgument
 */
function storeArrayAccessorWildcard(Request $request): RedirectResponse {
    $request->validate(['email.*' => 'email']);

    foreach ($request->array('email') as $email) {
        return redirect()->to($email);
    }

    return redirect()->to('/');
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
