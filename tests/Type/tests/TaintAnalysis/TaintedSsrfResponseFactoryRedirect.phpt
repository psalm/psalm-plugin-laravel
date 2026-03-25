--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Controller redirect helpers — user controls the redirect
 * target via request input.
 */
function responseRedirectTo(\Illuminate\Http\Request $request, \Illuminate\Routing\ResponseFactory $response): void {
    $next = (string) $request->input('next');
    $response->redirectTo($next);
}

function responseRedirectGuest(\Illuminate\Http\Request $request, \Illuminate\Routing\ResponseFactory $response): void {
    $returnUrl = (string) $request->input('return_url');
    $response->redirectGuest($returnUrl);
}

function responseRedirectToIntended(\Illuminate\Http\Request $request, \Illuminate\Routing\ResponseFactory $response): void {
    $fallback = (string) $request->input('fallback');
    $response->redirectToIntended($fallback);
}
?>
--EXPECTF--
%ATaintedSSRF on line %d: Detected tainted network request
%ATaintedHeader on line %d: Detected tainted header
%ATaintedSSRF on line %d: Detected tainted network request
%ATaintedHeader on line %d: Detected tainted header
%ATaintedSSRF on line %d: Detected tainted network request
%ATaintedHeader on line %d: Detected tainted header
