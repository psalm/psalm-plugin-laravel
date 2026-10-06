--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\CollectionChoiceDecide;

// `CollectionChoice::decide()` builds Classification::of($text) and new Choice($question, ...)
// inside vendor code, which Psalm never analyses, so the sinks sit on decide() itself.
function attackerControlledQuestion(\Illuminate\Http\Request $request, \Laravel\Ai\Classification\CollectionChoice $choice): mixed
{
    return $choice->decide((string) $request->input('question'), 'The customer asked about their invoice.');
}

function attackerControlledText(\Illuminate\Http\Request $request, \Laravel\Ai\Classification\CollectionChoice $choice): mixed
{
    return $choice->decide('Which team?', (string) $request->input('text'));
}

function cleanDecision(\Laravel\Ai\Classification\CollectionChoice $choice): mixed
{
    return $choice->decide('Which team?', 'The customer asked about their invoice.');
}
?>
--EXPECTF--
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
