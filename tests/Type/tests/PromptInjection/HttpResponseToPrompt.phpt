--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// PromptInjection fixtures need the optional laravel/ai integration installed (the plugin's
// laravel-ai stubs load only when Plugin::optionalIntegrationStubs() sees
// LaravelAiIntegration::isEnabled()); it is not a root composer.json
// dependency (PHP ^8.3 floor would break the PHP 8.2 CI lanes). Skip rather than fail when absent.
if (!\Psalm\LaravelPlugin\Internal\LaravelAiIntegration::isEnabled() || !trait_exists(\Laravel\Ai\Promptable::class)) {
    echo 'skip needs supported laravel/ai package (>=0.11.0 <1.0.0)';
}
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
TaintedCustom on line %d: Detected tainted llm_prompt
