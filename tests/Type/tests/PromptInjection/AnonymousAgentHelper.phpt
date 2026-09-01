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

function configurableSystemAgent(\Illuminate\Http\Request $request): \Laravel\Ai\Contracts\Agent {
    // The agent() helper is the friendliest way to spin up an inline agent;
    // it also makes the system prompt trivial to attacker-control if a
    // request value reaches the first argument.
    return \Laravel\Ai\agent((string) $request->input('sys'));
}
?>
--EXPECTF--
TaintedCustom on line %d: Detected tainted llm_prompt
