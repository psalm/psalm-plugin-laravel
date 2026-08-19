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
MissingReturnType on line %d: Method convertImage does not have a return type, expecting void
MixedAssignment on line %d: Unable to determine the type that $filename is being assigned to
TooFewArguments on line %d: Too few arguments for Illuminate\Process\PendingProcess::__construct - expecting factory to be passed
MixedArgument on line %d: Argument 1 of Illuminate\Process\PendingProcess::run cannot be mixed, expecting array<array-key, mixed>|null|string
TaintedShell on line %d: Detected tainted shell code
