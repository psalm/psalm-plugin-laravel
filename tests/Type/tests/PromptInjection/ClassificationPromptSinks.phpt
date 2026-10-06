--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\ClassificationPromptSinks;

// Each function is expected to raise exactly one TaintedLlmPrompt, in source order.
// `AnswersQuestions::mapQuestion()` serialises Boolean::$criteria, Choice::$options and
// Score::$levels into the request as the question's `criteria`, so each is prompt text.

function classificationState(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\ClassificationResponse
{
    return \Laravel\Ai\Classification::of((string) $request->input('state'))
        ->question('sentiment', new \Laravel\Ai\Classification\Boolean('Is the sentiment positive?'))
        ->classify();
}

function booleanInstructions(\Illuminate\Http\Request $request): \Laravel\Ai\Classification\Boolean
{
    return new \Laravel\Ai\Classification\Boolean((string) $request->input('instructions'));
}

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
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
