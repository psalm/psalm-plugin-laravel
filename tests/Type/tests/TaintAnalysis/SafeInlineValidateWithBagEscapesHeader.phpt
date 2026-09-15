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

    (new \Illuminate\Http\Client\PendingRequest())->get($request->input('contact_email'));
    return redirect()->to($request->input('contact_email'));
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
