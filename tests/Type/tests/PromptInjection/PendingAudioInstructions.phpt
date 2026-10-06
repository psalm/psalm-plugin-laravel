--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\AudioInstructions;

function ttsStyleFromRequest(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AudioResponse
{
    // Audio::of() text is already a sink; the free-form style instructions are
    // a second prompt handed to the same speech model.
    return \Laravel\Ai\Audio::of('Hello there.')
        ->instructions((string) $request->input('d'))
        ->generate();
}
?>
--EXPECTF--
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
