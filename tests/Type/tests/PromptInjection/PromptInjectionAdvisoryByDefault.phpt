--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// PromptInjection fixtures need the optional laravel/ai integration installed (the plugin's
// laravel-ai stubs load only when Plugin::optionalIntegrationStubs() sees
// isInstalledAndSatisfies('laravel/ai', '>=0.11.0 <1.0.0')); it is not a root composer.json
// dependency (PHP ^8.3 floor would break the PHP 8.2 CI lanes). Skip rather than fail when absent.
if (!trait_exists(\Laravel\Ai\Promptable::class)) {
    echo 'skip needs laravel/ai package (optional integration, not in composer.json)';
}
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-prompt-injection-default.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\PromptInjectionDefault;

final class DefaultLevelSupportAgent
{
    use \Laravel\Ai\Promptable;
}

/**
 * The shipped default: `TaintedLlmPrompt` is advisory, so this reports nothing
 * without `--show-info=true`. The identical flow under
 * `<findPromptInjection value="true" />` is DirectPromptInjection.phpt, which
 * expects the error.
 *
 * A chatbot passing the user's message to an agent is the product working, and
 * there is no sanitizer to point the developer at, so an error here would only
 * teach people to suppress the whole rule. The D-out direction is unaffected:
 * LlmOutputToSql.phpt, LlmOutputToHtml.phpt and LlmOutputToShell.phpt all still
 * report at their normal levels in this same run.
 */
function answerSupportQuestionAtDefaultLevel(\Illuminate\Http\Request $request): void {
    (new DefaultLevelSupportAgent())->prompt((string) $request->input('message'));
}

function llmOutputStillReachesSqlAtDefaultLevel(\Laravel\Ai\Responses\AgentResponse $response): void {
    // Control: proves this config really is running taint analysis, so the
    // silence above is the reporting level rather than a dead fixture.
    \Illuminate\Support\Facades\DB::select('SELECT * FROM notes WHERE body = ' . $response->text);
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
