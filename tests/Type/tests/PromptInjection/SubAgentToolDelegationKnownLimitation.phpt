--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// PromptInjection fixtures need the optional laravel/ai integration installed (the plugin's
// laravel-ai stubs load only when Plugin::optionalIntegrationStubs() sees
// isInstalledAndSatisfies('laravel/ai', '>=0.10.0 <1.0.0')); it is not a root composer.json
// dependency (PHP ^8.3 floor would break the PHP 8.2 CI lanes). Skip rather than fail when absent.
if (!trait_exists(\Laravel\Ai\Promptable::class)) {
    echo 'skip needs laravel/ai package (optional integration, not in composer.json)';
}
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\SubAgents;

final class ResearchSubAgent
{
    use \Laravel\Ai\Promptable;
}

/**
 * Known limitation. This mirrors `Laravel\Ai\Tools\AgentTool::handle()`: a parent
 * agent exposes another agent as a tool, and the task text the parent model chose
 * is forwarded verbatim into the sub-agent's prompt. `Tools\Request::offsetGet()`
 * carries `@psalm-taint-source input` and `Promptable::prompt()` is an
 * `llm_prompt` sink, so the composition should report `TaintedLlmPrompt`.
 *
 * It does not, because Psalm discards the taint edge when it resolves the
 * `$request['task']` sugar into an `offsetGet()` call
 * ([vimeo/psalm#11912](https://github.com/vimeo/psalm/issues/11912)).
 *
 * The `delegateExplicitly()` control below is what makes the silence meaningful.
 * `TaintFlowGraph::connectSinksAndSources()` prunes any node already visited with
 * the same taint mask, so an empty expectation against a sink shared with the
 * rest of the batch would hold even if the edge survived. Routing the control
 * through a per-file local sink proves the source is live and the sugar is the
 * only difference.
 *
 * When upstream lands, this test fails loudly. Replace it then with the positive
 * assertion (`TaintedLlmPrompt on line %d: Detected tainted LLM prompt`) and
 * delete the caveat in LlmOutputTaintHandler's docblock.
 */
final class ResearchDelegationTool implements \Laravel\Ai\Contracts\Tool
{
    #[\Override]
    public function description(): \Stringable|string
    {
        return 'Delegates a task to the research sub-agent.';
    }

    #[\Override]
    public function handle(\Laravel\Ai\Tools\Request $request): string
    {
        return (new ResearchSubAgent())->prompt((string) $request['task'])->text;
    }

    #[\Override]
    public function schema(\Illuminate\Contracts\JsonSchema\JsonSchema $schema): array
    {
        return ['task' => $schema->string()->required()];
    }
}

/** @psalm-taint-sink llm_prompt $prompt */
function subAgentPromptSink(string $prompt): int { return \strlen($prompt); }

function delegateExplicitly(\Laravel\Ai\Tools\Request $request): void {
    // Control: the desugared form of the `$request['task']` read above.
    subAgentPromptSink((string) $request->offsetGet('task'));
}
?>
--EXPECTF--
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
