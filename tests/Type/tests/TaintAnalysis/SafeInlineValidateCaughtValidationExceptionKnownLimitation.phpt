--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * KNOWN LIMITATION — worst case of the flow-insensitivity caveat.
 *
 * `$request->validate([...])` fires `AfterExpressionAnalysisEvent` during
 * the body walk even when the runtime call ends up throwing. Wrapping the
 * call in a try-block that catches (and swallows) `ValidationException`
 * means the exception path skips validation failure reporting and the
 * post-catch `$request->input('key')` reads UNVALIDATED data — but the
 * collector's cache was already populated, so the rule's escape still
 * applies.
 *
 * Reproduces as: redirect()->to() only reports TaintedSSRF; TaintedHeader
 * (which the `email` rule's escape clears) is silently dropped on the
 * thrown path.
 *
 * Realistic anti-pattern in defensive code. Prefer not to swallow
 * ValidationException at all, or use a typed FormRequest (framework
 * rejects the request before the controller body runs).
 *
 * This test locks the current behaviour so any future tightening (e.g.
 * only populating the cache from validate() statements not inside a
 * try-block) is a deliberate, reviewed change.
 */
/** @psalm-suppress MixedArgument */
function defensiveValidate(Request $request): RedirectResponse {
    try {
        $request->validate(['contact_email' => 'required|email']);
    } catch (ValidationException) {
        // defensive no-op — validation may have failed, but we proceed
    }

    return redirect()->to($request->input('contact_email'));
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
