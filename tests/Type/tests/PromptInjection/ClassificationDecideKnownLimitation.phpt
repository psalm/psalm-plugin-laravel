--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\ClassificationDecide;

// KNOWN LIMITATION: AiServiceProvider registers this as a Str macro, so Psalm sees only a
// Macroable pseudo-method. There is no per-method docblock where the llm_prompt sink can live.
// Caveat docblock: Laravel\Ai\Classification.
function decideAttackerControlledState(\Illuminate\Http\Request $request): void
{
    $state = (string) $request->input('state');

    \Illuminate\Support\Str::decide($state, 'Is this state safe?');
    \Illuminate\Support\Str::of($state)->decide('Is this state safe?');
}
?>
--EXPECTF--
