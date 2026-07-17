--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// PromptInjection fixtures need the optional laravel/ai integration installed (the plugin's
// laravel-ai stubs load only when Plugin::optionalIntegrationStubs() sees
// isInstalledAndSatisfies('laravel/ai', '>=0.9.0 <1.0.0')); it is not a root composer.json
// dependency (PHP ^8.3 floor would break the PHP 8.2 CI lanes). Skip rather than fail when absent.
if (!trait_exists(\Laravel\Ai\Promptable::class)) {
    echo 'skip needs laravel/ai package (optional integration, not in composer.json)';
}
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
