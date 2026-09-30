--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-prompt-injection-opt-out.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

final class ExplicitOptOutAgent
{
    use \Laravel\Ai\Promptable;
}

function explicitOptOutHidesPromptIssue(\Illuminate\Http\Request $request): void {
    (new ExplicitOptOutAgent)->prompt((string) $request->input('message'));
}

function explicitOptOutKeepsOutputTaint(\Laravel\Ai\Responses\AgentResponse $response): void {
    \Illuminate\Support\Facades\DB::select('SELECT * FROM notes WHERE body = ' . $response->text);
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
