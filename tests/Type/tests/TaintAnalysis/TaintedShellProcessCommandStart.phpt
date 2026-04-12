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
%ATaintedShell on line %d: Detected tainted shell code
%ATaintedShell on line %d: Detected tainted shell code
