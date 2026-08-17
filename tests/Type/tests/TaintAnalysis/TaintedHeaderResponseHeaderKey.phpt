--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function setHeaderKey(\Illuminate\Http\Request $request) {
    $key = $request->input('header_name');
    return response('OK')->header($key, 'static-value');
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header
