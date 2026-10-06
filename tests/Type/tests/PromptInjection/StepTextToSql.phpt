--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\StepText;

function queryFromStepText(\Laravel\Ai\Responses\AgentResponse $response): void {
    // Each recorded step carries the model text generated in that step.
    foreach ($response->steps as $step) {
        \Illuminate\Support\Facades\DB::select('SELECT * FROM notes WHERE body = ' . $step->text);
    }
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
