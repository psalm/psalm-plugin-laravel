--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function test(\Illuminate\Http\Request $request) {
    $url = $request->input('url');
    redirect($url);
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
TaintedHeader on line %d: Detected tainted header
