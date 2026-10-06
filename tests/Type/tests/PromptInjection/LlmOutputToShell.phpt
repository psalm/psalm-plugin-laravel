--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function runLlmSuggestedCommand(\Laravel\Ai\Responses\AgentResponse $response): void {
    // Agentic-coding pattern: the model returns a shell line, the wrapper runs it.
    $process = new \Illuminate\Process\PendingProcess();
    $process->run($response->text);
}
?>
--EXPECTF--
TooFewArguments on line %d: Too few arguments for Illuminate\Process\PendingProcess::__construct - expecting factory to be passed
TaintedShell on line %d: Detected tainted shell code
