--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// Psalm 7.0.0-beta21 drops one of two taint flows that reach the same sink method from two
// different files in a co-analyzed batch, so this fixture's finding disappears when the suite
// runs as a whole while it is still reported on its own.
// @todo-by 2026-10-01 drop this gate once vimeo/psalm#11959 ships a fix; if the issue is still
// open then, re-check whether the batch still hides the finding and move the date.
// @see https://github.com/vimeo/psalm/issues/11959
\Tests\Psalm\LaravelPlugin\Type\PsalmVersion::skipOnRange('7.0.0-beta21', '7.0.0-beta22');
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
    (new \Illuminate\Http\Client\PendingRequest())->get($request->input('contact_email'));
    return redirect()->to($request->input('contact_email'));
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
