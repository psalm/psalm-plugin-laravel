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
if (!class_exists(\Laravel\Ai\PendingStep::class)) {
    echo 'skip needs laravel/ai >= 1.0';
    return;
}
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\PendingStepMiddleware;

final class AddRequestInstructions
{
    public function __construct(private readonly \Illuminate\Http\Request $request) {}

    public function handle(\Laravel\Ai\PendingStep $step, \Closure $next): mixed
    {
        return $next($step->withInstructions((string) $this->request->input('instructions')));
    }
}

final class SupportAgent implements \Laravel\Ai\Contracts\HasMiddleware
{
    use \Laravel\Ai\Promptable;

    public function __construct(private readonly AddRequestInstructions $requestInstructions) {}

    /** @return list<AddRequestInstructions> */
    #[\Override]
    public function middleware(): array
    {
        return [$this->requestInstructions];
    }
}

function promptThroughStepMiddleware(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
{
    return (new SupportAgent(new AddRequestInstructions($request)))
        ->prompt('Give the customer a concise answer.');
}
?>
--EXPECTF--
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
