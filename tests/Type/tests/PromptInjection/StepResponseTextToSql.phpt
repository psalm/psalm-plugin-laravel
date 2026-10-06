--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\StepResponseText;

function queryFromGatewayStep(\Laravel\Ai\Gateway\StepResponse $step): void {
    // Agent middleware receives the gateway step through `$next($step)->response()`.
    \Illuminate\Support\Facades\DB::select('SELECT * FROM notes WHERE body = ' . $step->text);
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
