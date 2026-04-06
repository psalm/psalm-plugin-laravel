--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function setHeader(\Illuminate\Http\Request $request) {
    $value = $request->input('x_custom');
    return response('OK')->header('X-Custom', $value);
}
?>
--EXPECTF--
%ATaintedHeader on line %d: Detected tainted header
