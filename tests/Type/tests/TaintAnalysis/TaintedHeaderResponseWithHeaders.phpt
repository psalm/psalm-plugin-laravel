--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function setHeaders(\Illuminate\Http\Request $request) {
    $headers = ['X-Custom' => $request->input('x_custom')];
    return response('OK')->withHeaders($headers);
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header
