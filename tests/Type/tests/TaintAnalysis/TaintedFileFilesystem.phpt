--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function test(\Illuminate\Http\Request $request) {
    $path = $request->input('path');
    $fs = new \Illuminate\Filesystem\Filesystem();
    $fs->get($path);
}
?>
--EXPECTF--
TaintedFile on line %d: Detected tainted file handling
