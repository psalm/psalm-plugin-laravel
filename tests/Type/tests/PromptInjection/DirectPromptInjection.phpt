--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Agents;

final class SupportAgent
{
    use \Laravel\Ai\Promptable;
}

function askSupport(\Illuminate\Http\Request $request): \Laravel\Ai\Responses\AgentResponse {
    $question = (string) $request->input('q');

    return (new SupportAgent())->prompt($question);
}
?>
--EXPECTF--
TaintedCustom on line %d: Detected tainted llm_prompt
