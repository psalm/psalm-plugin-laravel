--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// PromptInjection fixtures need the optional laravel/ai integration installed (the plugin's
// laravel-ai stubs load only when Plugin::optionalIntegrationStubs() sees
// isInstalledAndSatisfies('laravel/ai', '>=0.10.0 <1.0.0')); it is not a root composer.json
// dependency (PHP ^8.3 floor would break the PHP 8.2 CI lanes). Skip rather than fail when absent.
if (!trait_exists(\Laravel\Ai\Promptable::class)) {
    echo 'skip needs laravel/ai package (optional integration, not in composer.json)';
}
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\StructuredOutput;

function renderStructuredAgentField(\Laravel\Ai\Responses\StructuredAgentResponse $response): void {
    // Structured output is still model output: the JSON keys are schema-controlled but
    // the values are not. Array access reaches ProvidesStructuredResponse::offsetGet().
    echo (string) $response['summary'];
}

function renderStructuredTextField(\Laravel\Ai\Responses\StructuredTextResponse $response): void {
    // Same trait, different hierarchy (TextResponse rather than AgentResponse).
    echo (string) $response['summary'];
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
