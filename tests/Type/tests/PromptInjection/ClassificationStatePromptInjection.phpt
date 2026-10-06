--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\ClassificationState;

function classifyAttackerControlledState(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\ClassificationResponse
{
    return \Laravel\Ai\Classification::of((string) $request->input('state'))
        ->question('sentiment', new \Laravel\Ai\Classification\Boolean('Is the sentiment positive?'))
        ->classify();
}
?>
--EXPECTF--
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
