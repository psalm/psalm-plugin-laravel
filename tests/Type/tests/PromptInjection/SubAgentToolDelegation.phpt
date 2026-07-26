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
 * Mirrors Laravel\Ai\Tools\AgentTool::handle(): a parent agent exposes another agent
 * as a tool, and the task text the parent model chose is forwarded verbatim into the
 * sub-agent's prompt. No annotation covers this chain directly; it holds only because
 * Request::offsetGet() is a source and Promptable::prompt() is a sink, so a refactor
 * of either stub would silently drop it.
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
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
