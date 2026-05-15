--ARGS--
--threads=1 --no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;

/**
 * `collect()` is the sibling of `array()` — same data pool, different
 * return type (`Collection` vs raw array). Issue #840 adds both to
 * `KEYED_ACCESSOR_METHODS` so the rule's escape applies to either.
 *
 * Foreach over a `Collection` does not propagate taint to elements in
 * Psalm 7 (Collection's iterator is opaque to taint flow), so this test
 * exercises the direct-pass shape: the Collection is passed to a sink
 * that accepts iterable arguments. The escape on the call expression's
 * outgoing taint applies — `Mail::cc()` does not fire `TaintedHeader`.
 *
 * The companion `array()` test
 * (`SafeInlineValidateArrayAccessorWildcardEscapesHeader`) covers the
 * `foreach` shape, which works because raw arrays use `arrayvalue-fetch`
 * for element extraction and the loop-variable cache populated in
 * `beforeStatementAnalysis` keeps the escape attached.
 *
 * --threads=1: see SafeInlineValidateCustomRuleEscapesHeaderViaVariable
 * for why these tests pin to a single Psalm thread.
 */
function storeCollectAccessorWildcard(Request $request, Mailable $mail): void {
    $request->validate(['email.*' => 'email']);
    $mail->cc($request->collect('email'));
}
?>
--EXPECTF--

