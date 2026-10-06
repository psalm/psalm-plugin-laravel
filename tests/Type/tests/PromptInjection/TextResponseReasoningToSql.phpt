--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\ReasoningOutput;

function queryFromModelReasoning(\Laravel\Ai\Responses\TextResponse $response): void
{
    \Illuminate\Support\Facades\DB::select('SELECT * FROM audit WHERE note = ' . $response->reasoning);
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
