--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function test(\Illuminate\Http\Request $request) {
    $input = $request->input('name');
    return response($input);
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
