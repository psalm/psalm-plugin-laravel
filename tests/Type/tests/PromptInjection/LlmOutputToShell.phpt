--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// PromptInjection fixtures need the optional laravel/ai integration installed (the plugin's
// laravel-ai stubs load only when Plugin::optionalIntegrationStubs() sees
// LaravelAiIntegration::isEnabled()); it is not a root composer.json
// dependency (PHP ^8.3 floor would break the PHP 8.2 CI lanes). Skip rather than fail when absent.
if (!\Psalm\LaravelPlugin\Internal\LaravelAiIntegration::isEnabled() || !trait_exists(\Laravel\Ai\Promptable::class)) {
    echo 'skip needs supported laravel/ai package (>=0.11.0 <1.0.0)';
}
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function runLlmSuggestedCommand(\Laravel\Ai\Responses\AgentResponse $response): void {
    // Agentic-coding pattern: the model returns a shell line, the wrapper runs it.
    // `new PendingProcess()` under-supplies the mandatory $factory constructor arg;
    // Psalm 7/master flags that as TooFewArguments, Psalm 6 does not (confirmed
    // empirically, an argument-count-checking core difference unrelated to this
    // plugin) — TaintedShell alone is the correct, complete finding on this branch.
    $process = new \Illuminate\Process\PendingProcess();
    $process->run($response->text);
}
?>
--EXPECTF--
TaintedShell on line %d: Detected tainted shell code
