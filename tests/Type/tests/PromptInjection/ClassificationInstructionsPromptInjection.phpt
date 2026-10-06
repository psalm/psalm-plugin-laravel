--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
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
