--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function test(\Illuminate\Http\Request $request) {
    $input = $request->input('name');
    echo e($input);
}
?>
--EXPECTF--
