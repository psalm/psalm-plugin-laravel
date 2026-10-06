--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// PromptInjection fixtures need the optional laravel/ai integration installed (the plugin's
// laravel-ai stubs load only when Plugin::optionalIntegrationStubs() sees
// LaravelAiIntegration::isEnabled()); it is not a root composer.json
// dependency (PHP ^8.3 floor would break the PHP 8.2 CI lanes). Skip rather than fail when absent.
if (!\Psalm\LaravelPlugin\Internal\LaravelAiIntegration::isEnabled() || !trait_exists(\Laravel\Ai\Promptable::class)) {
    echo 'skip needs supported laravel/ai package (>=1.0.0 <2.0.0)';
}
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\AdHocMessages;

final class SupportAgent
{
    use \Laravel\Ai\Promptable;
}

function promptWithAttackerControlledHistory(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
{
    $history = [[
        'role' => 'user',
        'content' => (string) $request->input('history'),
    ]];

    return (new SupportAgent())
        ->withMessages($history)
        ->prompt('Summarize the support case.');
}
?>
--EXPECTF--
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
