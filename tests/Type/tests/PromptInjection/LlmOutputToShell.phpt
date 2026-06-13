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
%ATaintedShell on line %d: Detected tainted shell code
