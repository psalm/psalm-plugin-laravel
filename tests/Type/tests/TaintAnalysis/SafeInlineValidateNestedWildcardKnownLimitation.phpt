--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * KNOWN LIMITATION: nested wildcards (`'addresses.*.email'`) accessed via
 * a dotted path (`$request->input('addresses.0.email')`) do NOT inherit
 * the element rule's taint-escape. The storage-normalisation and lookup
 * fallback added for #838 handle the single leaf-wildcard form only; the
 * nested case would need generalised segment-by-segment rewriting that is
 * out of scope for this pass (see issue #838's "Out of scope" section).
 *
 * This test locks in the current behaviour so any future deeper-walk
 * implementation is a deliberate, reviewed change that flips the
 * expectation (adds a `TaintedHeader` line) rather than a silent regression.
 */
/** @psalm-suppress MixedArgument */
function storeNestedWildcard(Request $request): RedirectResponse {
    $request->validate([
        'addresses.*.email' => ['required', Rule::email()],
    ]);

    return redirect()->to($request->input('addresses.0.email'));
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header
TaintedSSRF on line %d: Detected tainted network request
