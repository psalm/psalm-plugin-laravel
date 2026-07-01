--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function configurableSystemAgent(\Illuminate\Http\Request $request): \Laravel\Ai\Contracts\Agent {
    // The agent() helper is the friendliest way to spin up an inline agent;
    // it also makes the system prompt trivial to attacker-control if a
    // request value reaches the first argument.
    return \Laravel\Ai\agent((string) $request->input('sys'));
}
?>
--EXPECTF--
%ATaintedLlmPrompt on line %d: Detected tainted LLM prompt
