--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function test(\Illuminate\Http\Request $request) {
    $input = $request->input('cmd');
    $process = new \Illuminate\Process\PendingProcess();
    $process->run($input);
}
?>
--EXPECTF--
TaintedShell on line %d: Detected tainted shell code
