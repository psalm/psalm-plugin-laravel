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

function executeLlmCommand(\Laravel\Ai\Responses\AgentResponse $response): void {
    // Classic LLM-output-to-SQL: model picks a column/condition based on user intent,
    // app interpolates the raw text into a raw query.
    \Illuminate\Support\Facades\DB::select($response->text);
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
