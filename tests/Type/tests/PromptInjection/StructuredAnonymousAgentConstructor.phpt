--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\StructuredAnonymous;

function structuredAgentWithAttackerControlledInstructions(\Illuminate\Http\Request $request): \Laravel\Ai\StructuredAnonymousAgent
{
    // StructuredAnonymousAgent overrides the AnonymousAgent constructor, so the
    // parent's sinks do not carry over on their own.
    return new \Laravel\Ai\StructuredAnonymousAgent(
        (string) $request->input('d'),
        [],
        [],
        static fn (): array => [],
    );
}
?>
--EXPECTF--
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
