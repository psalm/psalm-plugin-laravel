--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
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
