--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function renderChatTurn(\Laravel\Ai\Responses\AgentResponse $response): void {
    // Most common XSS source in chat UIs: server-rendered LLM output dropped
    // directly into the response body without escaping.
    echo $response->text;
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
