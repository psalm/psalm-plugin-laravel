--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * ResponseFactory methods that render user-controlled content into a document the browser
 * parses are html-taint sinks — an attacker could inject scripts or markup.
 *
 * `make()` writes the response body directly. `jsonp()` sinks BOTH params because a JSONP
 * response is served as, and executed as, script.
 *
 * `json()` is deliberately absent: it is served as application/json and is pinned clean in
 * SafeResponseFactoryJsonNoHtmlTaint.phpt.
 */

function responseMake(\Illuminate\Http\Request $request, \Illuminate\Routing\ResponseFactory $response): void {
    $response->make($request->input('body'));
}

function responseJsonp(\Illuminate\Http\Request $request, \Illuminate\Routing\ResponseFactory $response): void {
    $response->jsonp($request->input('callback'), $request->input('data'));
}

?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
MixedArgument on line %d: Argument 1 of Illuminate\Routing\ResponseFactory::jsonp cannot be mixed, expecting string
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
