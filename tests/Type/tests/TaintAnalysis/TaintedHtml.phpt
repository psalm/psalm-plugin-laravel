--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function test_db_raw(\Illuminate\Http\Request $request) {
    $taint_input = $request->input('foo');

    return new \Illuminate\Http\Response($taint_input);
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
