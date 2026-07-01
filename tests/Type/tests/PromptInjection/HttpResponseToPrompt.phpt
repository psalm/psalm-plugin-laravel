--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\Agents;

final class FetchAgent
{
    use \Laravel\Ai\Promptable;
}

function summarizeRemoteDocument(\Illuminate\Http\Client\Response $response): \Laravel\Ai\Responses\AgentResponse {
    // Indirect prompt injection: the fetched body may contain hostile instructions
    // a third party planted on the target page or RAG corpus.
    return (new FetchAgent())->prompt($response->body());
}
?>
--EXPECTF--
%ATaintedLlmPrompt on line %d: Detected tainted LLM prompt
