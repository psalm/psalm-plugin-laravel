--ARGS--
--threads=1 --no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;

/**
 * Variable-binding variant: `$emails = $request->array('email')` flows
 * through the existing variable-binding cache (issue #834). The
 * subsequent direct pass of `$emails` to a header sink that accepts
 * iterable (`Mail::cc()`) inherits the email rule's header/cookie
 * escape, so no TaintedHeader fires.
 *
 * The foreach companion
 * (`SafeInlineValidateArrayAccessorWildcardEscapesHeader`) covers the
 * direct-call iteration shape; this test exercises the variable-bound
 * shape without iteration so the new path doesn't conflate the two.
 *
 * --threads=1: see SafeInlineValidateCustomRuleEscapesHeaderViaVariable
 * for why these tests pin to a single Psalm thread.
 */
function storeArrayAccessorWildcardViaVariable(Request $request, Mailable $mail): void {
    $request->validate(['email.*' => 'email']);
    $emails = $request->array('email');
    $mail->cc($emails);
}
?>
--EXPECTF--
