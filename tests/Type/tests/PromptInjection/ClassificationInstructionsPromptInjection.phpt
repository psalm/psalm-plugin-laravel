--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// PromptInjection fixtures need the optional laravel/ai integration installed (the plugin's
// laravel-ai stubs load only when Plugin::optionalIntegrationStubs() sees
// LaravelAiIntegration::isEnabled()); it is not a root composer.json
// dependency (PHP ^8.3 floor would break the PHP 8.2 CI lanes). Skip rather than fail when absent.
if (!\Psalm\LaravelPlugin\Internal\LaravelAiIntegration::isEnabled() || !trait_exists(\Laravel\Ai\Promptable::class)) {
    echo 'skip needs supported laravel/ai package (>=0.11.0 <2.0.0)';
}
if (!class_exists(\Laravel\Ai\Classification\Boolean::class)) {
    echo 'skip needs laravel/ai >= 1.0';
    return;
}
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\ClassificationInstructions;

function classifyWithAttackerControlledInstructions(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\ClassificationResponse
{
    return \Laravel\Ai\Classification::of('The customer asked about their invoice.')
        ->question('sentiment', new \Laravel\Ai\Classification\Boolean((string) $request->input('instructions')))
        ->classify();
}
?>
--EXPECTF--
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
