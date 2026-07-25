--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\ResponseFactory;

// json() is NOT an html sink. Laravel JSON-encodes $data and serves it as
// application/json, which browsers do not render as HTML. Reaching XSS would need legacy
// content-sniffing AND the app dropping nosniff, too weak a chain for a default-on sink.
// Empty --EXPECTF-- so a re-added sink fails loudly. jsonp() stays a sink and is pinned in
// TaintedHtmlResponseFactory.phpt: that response IS executed as script.
function jsonViaContract(Request $request): void
{
    response()->json($request->input('data'));
}

function jsonViaConcrete(Request $request, ResponseFactory $response): void
{
    $response->json($request->input('data'));
}
?>
--EXPECTF--
