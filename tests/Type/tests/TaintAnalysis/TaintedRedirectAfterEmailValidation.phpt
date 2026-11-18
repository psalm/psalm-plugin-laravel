--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `//evil.example@x.example` is a valid email address, and a redirect to it goes to the server x.example: the
 * 'email' rule escapes the header taint, not the redirect one.
 *
 * @psalm-suppress MixedArgument
 */
function store(Request $request): RedirectResponse {
    $request->validate(['next' => 'required|email']);

    return redirect()->to($request->input('next'));
}
?>
--EXPECTF--
TaintedRedirect on line %d: Detected tainted redirect target
