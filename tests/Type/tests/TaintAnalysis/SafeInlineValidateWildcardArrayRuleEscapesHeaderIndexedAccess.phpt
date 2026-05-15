--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Wildcard-suffix rule (`'email.*' => [..., Rule::email()]`) plus indexed
 * access (`$request->input('email.0')`) must inherit the element rule's
 * escape. Laravel's input() resolves dotted keys against the request bag,
 * so `'email.0'` reads the first array element. The rule map stores the
 * resolved rule under the parent key `'email'`; the lookup strips a single
 * trailing numeric segment to find it.
 *
 * TaintedSSRF still fires: a validated email's domain may still resolve to
 * an internal host.
 */
/** @psalm-suppress MixedArgument */
function storeWildcardIndexed(Request $request): RedirectResponse {
    $request->validate([
        'email.*' => ['required', Rule::email()],
    ]);

    return redirect()->to($request->input('email.0'));
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
