--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\ClassificationCriteria;

// `AnswersQuestions::mapQuestion()` serialises Boolean::$criteria, Choice::$options and
// Score::$levels into the request as the question's `criteria`, so each is prompt text.
function booleanCriteria(\Illuminate\Http\Request $request): \Laravel\Ai\Classification\Boolean
{
    return new \Laravel\Ai\Classification\Boolean('Is this safe?', ['true' => (string) $request->input('d')]);
}

function choiceOptions(\Illuminate\Http\Request $request): \Laravel\Ai\Classification\Choice
{
    return new \Laravel\Ai\Classification\Choice('Which team?', ['sales' => (string) $request->input('d')]);
}

function scoreLevels(\Illuminate\Http\Request $request): \Laravel\Ai\Classification\Score
{
    return new \Laravel\Ai\Classification\Score('How urgent?', [1 => (string) $request->input('d')]);
}
?>
--EXPECTF--
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
