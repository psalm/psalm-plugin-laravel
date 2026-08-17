--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * PendingProcess::command() and ::start() are shell sinks —
 * user-controlled input passed as a command enables arbitrary
 * command execution.
 */

function processCommand(\Illuminate\Http\Request $request): void {
    $process = new \Illuminate\Process\PendingProcess();
    $process->command($request->input('cmd'));
}

function processStart(\Illuminate\Http\Request $request): void {
    $process = new \Illuminate\Process\PendingProcess();
    $process->start($request->input('cmd'));
}
?>
--EXPECTF--
TooFewArguments on line %d: Too few arguments for Illuminate\Process\PendingProcess::__construct - expecting factory to be passed
MixedArgument on line %d: Argument 1 of Illuminate\Process\PendingProcess::command cannot be mixed, expecting array<array-key, mixed>|string
TaintedShell on line %d: Detected tainted shell code
TooFewArguments on line %d: Too few arguments for Illuminate\Process\PendingProcess::__construct - expecting factory to be passed
MixedArgument on line %d: Argument 1 of Illuminate\Process\PendingProcess::start cannot be mixed, expecting array<array-key, mixed>|null|string
TaintedShell on line %d: Detected tainted shell code
