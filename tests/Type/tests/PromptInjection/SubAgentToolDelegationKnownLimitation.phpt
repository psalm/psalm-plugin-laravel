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
 * Known limitation. Empty expectations assert the CURRENT behavior: nothing is
 * reported, even though the flow is real.
 *
 * This mirrors Laravel\Ai\Tools\AgentTool::handle(): a parent agent exposes
 * another agent as a tool, and the task text the parent model chose is forwarded
 * verbatim into the sub-agent's prompt. Tools\Request::offsetGet() carries
 * `@psalm-taint-source input` and Promptable::prompt() is an `llm_prompt` sink,
 * so the composition should report TaintedLlmPrompt.
 *
 * It does not, because Psalm's ArrayFetchAnalyzer resolves the `$request['task']`
 * sugar by synthesizing a VirtualMethodCall to offsetGet() in a cloned node-data
 * set and copying back only the resulting type. The taint edge lives in the clone
 * and is discarded. That breaks every ArrayAccess-based taint source, so it is a
 * Psalm core gap rather than a Laravel one, and it is left for an upstream fix.
 * Writing the same call as `$request->offsetGet('task')` does report.
 *
 * When upstream lands, this test fails loudly. Replace it then with the positive
 * assertion (`%ATaintedLlmPrompt on line %d: Detected tainted LLM prompt`) and
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
?>
--EXPECTF--
