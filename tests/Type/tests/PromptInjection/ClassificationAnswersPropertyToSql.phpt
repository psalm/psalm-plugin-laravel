--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\ClassificationAnswers;

function queryFromAnswers(\Laravel\Ai\Responses\ClassificationResponse $response): void {
    \Illuminate\Support\Facades\DB::select('SELECT * FROM topics WHERE answers = ' . \serialize($response->answers));
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
