--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
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
 * The shipped default is auto: inside the supported laravel/ai integration,
 * omitted configuration keeps TaintedLlmPrompt at Psalm's normal error level.
 * The D-out direction remains an ordinary taint source and still reports at its
 * normal level in this same run.
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
TaintedLlmPrompt on line %d: Detected tainted LLM prompt
TaintedSql on line %d: Detected tainted SQL
