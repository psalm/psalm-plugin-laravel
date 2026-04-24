--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * KNOWN LIMITATION: the collector is flow-insensitive within a function.
 * A `validate()` inside an `if` branch is treated as if it always ran,
 * so a subsequent `input()` outside the branch still benefits from the
 * escape — even on execution paths where the validation was skipped.
 *
 * Unlike the FormRequest path (where `ValidatesWhenResolvedTrait`
 * guarantees validation runs before the controller method is entered),
 * the inline form has no framework-level guarantee. Flow-sensitive
 * modelling of arbitrary control structures is out of scope for the
 * plugin; prefer a typed FormRequest when the guarantee matters.
 *
 * This test locks the current behaviour so any future tightening is a
 * deliberate, reviewed change.
 */
/** @psalm-suppress MixedArgument */
function conditionalProbe(Request $request, bool $trusted): RedirectResponse {
    if ($trusted) {
        $request->validate(['contact_email' => 'required|email']);
    }

    // The escape applies here even when $trusted === false and no
    // validation ran. Analyzer reports only TaintedSSRF (which the email
    // rule does not escape); TaintedHeader is silently suppressed.
    return redirect()->to($request->input('contact_email'));
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
