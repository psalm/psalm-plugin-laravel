--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function convertImage(\Illuminate\Http\Request $request) {
    $filename = $request->input('filename');
    $process = new \Illuminate\Process\PendingProcess();
    $process->run($filename);
}
?>
--EXPECTF--
TaintedShell on line %d: Detected tainted shell code
