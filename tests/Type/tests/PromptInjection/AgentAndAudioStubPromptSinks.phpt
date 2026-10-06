--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\AgentAndAudioSinks;

final class SupportAgent
{
    use \Laravel\Ai\Promptable;
}

// Each function is expected to raise exactly one TaintedLlmPrompt, in source order.

// An assistant turn replayed from the client is still model input: the attacker can
// forge what "the model said" earlier in the conversation.
function assistantMessageReplay(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse
{
    return (new SupportAgent())
        ->withMessages([new \Laravel\Ai\Messages\AssistantMessage((string) $request->input('d'))])
        ->prompt('Continue the conversation.');
}

// StructuredAnonymousAgent overrides the AnonymousAgent constructor, so the parent's
// sinks do not carry over on their own.
function structuredAnonymousAgentInstructions(\Illuminate\Http\Request $request): \Laravel\Ai\StructuredAnonymousAgent
{
    return new \Laravel\Ai\StructuredAnonymousAgent(
        (string) $request->input('d'),
        [],
        [],
        static fn (): array => [],
    );
}

// Audio::of() text is already a sink; the free-form style instructions are a second
// prompt handed to the same speech model.
function pendingAudioInstructions(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AudioResponse
{
    return \Laravel\Ai\Audio::of('Hello there.')
        ->instructions((string) $request->input('d'))
        ->generate();
}
?>
--EXPECTF--
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
