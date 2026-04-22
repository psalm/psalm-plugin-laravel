--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Inline `$request->validate([...])` with `Rule::email()` carries the same
 * header/cookie escape as the 'email' string rule. TaintedSSRF is still
 * reported: a valid email address's domain can resolve to an internal host.
 */
/** @psalm-suppress MixedArgument */
function store(Request $request): RedirectResponse {
    $request->validate([
        'reply_to' => ['required', Rule::email()],
    ]);

    return redirect()->to($request->input('reply_to'));
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
