--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `$request->validateWithBag('bagName', [...])` is the Named Error Bag
 * counterpart of `validate()`, registered as a macro by
 * FoundationServiceProvider. It validates the same data pool, just with a
 * distinct error-bag target. The collector must accept it with rules at
 * arg[1]. Fortify-style controllers use this pattern heavily.
 */
/** @psalm-suppress MixedArgument */
function storeWithBag(Request $request): RedirectResponse {
    $request->validateWithBag('contactForm', [
        'contact_email' => ['required', 'email'],
    ]);

    return redirect()->to($request->input('contact_email'));
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
