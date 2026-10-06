--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\AssistantReplay;

final class SupportAgent
{
    use \Laravel\Ai\Promptable;
}

function replayAttackerControlledAssistantTurn(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
{
    // An assistant turn replayed from the client is still model input: the
    // attacker can forge what "the model said" earlier in the conversation.
    return (new SupportAgent())
        ->withMessages([new \Laravel\Ai\Messages\AssistantMessage((string) $request->input('d'))])
        ->prompt('Continue the conversation.');
}
?>
--EXPECTF--
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
