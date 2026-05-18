--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function renderEscapedChatTurn(\Laravel\Ai\Responses\AgentResponse $response): void {
    // Same shape as LlmOutputToHtml.phpt, but routed through Laravel's e() helper.
    // e() is annotated as @psalm-taint-escape html, so no Tainted* issue should fire.
    echo e($response->text);
}
?>
--EXPECTF--
