--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Agents;

final class SummariesAgent
{
    use \Laravel\Ai\Promptable;
}

function summarizeWeather(): \Laravel\Ai\Responses\AgentResponse {
    return (new SummariesAgent())->prompt('Describe the weather in Paris in one sentence.');
}
?>
--EXPECTF--
