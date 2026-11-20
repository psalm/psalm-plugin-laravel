--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/**
 * The redirect helpers of the ResponseFactory contract (what `response()` gives with no argument):
 * the user controls the redirect target through request input.
 */
function contractRedirectTo(\Illuminate\Http\Request $request, \Illuminate\Contracts\Routing\ResponseFactory $response): void {
    $next = (string) $request->input('next');
    $response->redirectTo($next);
}

function contractRedirectGuest(\Illuminate\Http\Request $request, \Illuminate\Contracts\Routing\ResponseFactory $response): void {
    $returnUrl = (string) $request->input('return_url');
    $response->redirectGuest($returnUrl);
}

function contractRedirectToIntended(\Illuminate\Http\Request $request, \Illuminate\Contracts\Routing\ResponseFactory $response): void {
    $fallback = (string) $request->input('fallback');
    $response->redirectToIntended($fallback);
}
?>
--EXPECTF--
TaintedRedirect on line %d: Detected tainted redirect target
TaintedRedirect on line %d: Detected tainted redirect target
TaintedRedirect on line %d: Detected tainted redirect target
