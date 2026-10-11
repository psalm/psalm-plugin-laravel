--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
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
