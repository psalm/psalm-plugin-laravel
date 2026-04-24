--ARGS--
--threads=1 --no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * KNOWN LIMITATION: `$v = $request->input('k') ?? 'default'` does not
 * populate the per-variable escape cache. The RHS is `BinaryOp\Coalesce`
 * rather than a direct keyed-accessor call, and the default expression
 * on the right of `??` can carry independent taint that the rule's
 * escape would incorrectly strip if we applied it. Fail-safe: the
 * binding keeps whatever taint the coalesced value has, so header/SSRF
 * sinks on `$v` still fire.
 *
 * This test locks in that behaviour so any future tightening (e.g.
 * walking inside the Coalesce left operand) is a deliberate, reviewed
 * change. Mirrors the `$v = $request->input('k')` sketch from #834.
 *
 * --threads=1: see SafeInlineValidateCustomRuleEscapesHeaderViaVariable
 * for why these tests pin to a single Psalm thread.
 */
/**
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArgument
 */
function coalesceDefaultProbe(Request $request): RedirectResponse {
    $request->validate(['k' => 'email']);

    $coalescedBound = $request->input('k') ?? 'default';

    return redirect()->to($coalescedBound);
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header
TaintedSSRF on line %d: Detected tainted network request
