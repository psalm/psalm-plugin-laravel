--ARGS--
--threads=1 --no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Variable-binding variant of SafeInlineValidateRuleEmailEscapesHeader.
 *
 * `Rule::email()` carries the same header/cookie escape as the `email`
 * string rule. Binding the input read to a local variable before the
 * sink call must preserve the escape (#834). TaintedSSRF is still
 * reported: a valid email's domain may still resolve to an internal host.
 *
 * --threads=1: see SafeInlineValidateCustomRuleEscapesHeaderViaVariable
 * for why these tests pin to a single Psalm thread.
 */
/**
 * @psalm-suppress MixedAssignment
 * @psalm-suppress MixedArgument
 */
function storeRuleEmailViaVariable(Request $request): RedirectResponse {
    $request->validate([
        'reply_to' => ['required', Rule::email()],
    ]);

    $boundReplyTo = $request->input('reply_to');

    return redirect()->to($boundReplyTo);
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
