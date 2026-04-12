--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * ResponseFactory methods that render user-controlled content
 * are html-taint sinks — an attacker could inject scripts or
 * markup into the response body.
 */

function responseMake(\Illuminate\Http\Request $request, \Illuminate\Routing\ResponseFactory $response): void {
    $response->make($request->input('body'));
}

function responseJson(\Illuminate\Http\Request $request, \Illuminate\Routing\ResponseFactory $response): void {
    $response->json($request->input('data'));
}

function responseJsonp(\Illuminate\Http\Request $request, \Illuminate\Routing\ResponseFactory $response): void {
    $response->jsonp($request->input('callback'), $request->input('data'));
}

?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedHtml on line %d: Detected tainted HTML
