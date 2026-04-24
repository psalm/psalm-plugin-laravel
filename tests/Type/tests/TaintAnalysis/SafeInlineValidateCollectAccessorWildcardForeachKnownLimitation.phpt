--ARGS--
--threads=1 --no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * KNOWN LIMITATION — `foreach ($req->collect('k') as $e)` does NOT
 * propagate taint to the loop variable in Psalm 7.
 *
 * `Collection` iteration in Psalm 7 is opaque to the taint flow
 * machinery — Psalm doesn't model `Collection`'s `getIterator()` /
 * `IteratorAggregate` for taint propagation, so the loop variable
 * carries no taint at all. This means BOTH the rule's escape AND the
 * source taint are dropped, which is the wrong fail-safe direction
 * (silent false negative on un-validated reads of the same shape).
 *
 * The companion `SafeInlineValidateCollectAccessorWildcardEscapesHeader`
 * test covers the supported direct-pass shape (`$mail->cc($req->collect('k'))`)
 * where the call-expression `removeTaints` mask applies. The
 * `SafeInlineValidateArrayAccessorWildcardEscapesHeader` companion
 * covers the working `array()` foreach shape, which works because raw
 * arrays use `arrayvalue-fetch` (not `IteratorAggregate`) for element
 * extraction.
 *
 * This test asserts the current (lossy) behaviour by checking that
 * NEITHER `TaintedHeader` NOR `TaintedSSRF` fires inside the foreach
 * body — i.e. taint did not propagate at all. If a future Psalm
 * version (or a Collection stub change in this plugin) starts
 * propagating taint through `Collection` iteration, this test will
 * flip and fail, forcing a deliberate review of the foreach population
 * path for `collect()`.
 *
 * --threads=1: see SafeInlineValidateCustomRuleEscapesHeaderViaVariable
 * for why these tests pin to a single Psalm thread.
 */
/**
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArgument
 */
function storeCollectAccessorWildcardForeach(Request $request): RedirectResponse {
    $request->validate(['email.*' => 'email']);

    foreach ($request->collect('email') as $email) {
        return redirect()->to($email);
    }

    return redirect()->to('/');
}
?>
--EXPECTF--
