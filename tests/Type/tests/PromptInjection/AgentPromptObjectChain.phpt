--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function buildAttackerControlledPrompt(
    \Illuminate\Http\Request $request,
    \Laravel\Ai\Prompts\AgentPrompt $base,
): \Laravel\Ai\Prompts\AgentPrompt {
    // Direct construction of AgentPrompt bypasses the Promptable trait sinks;
    // the stub layers `@psalm-taint-sink llm_prompt $prompt` onto prepend() and
    // friends so this branch is still detected.
    return $base->prepend((string) $request->input('preamble'));
}
?>
--EXPECTF--
%ATaintedLlmPrompt on line %d: Detected tainted LLM prompt
