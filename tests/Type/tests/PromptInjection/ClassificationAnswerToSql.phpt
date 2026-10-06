--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\ClassificationAnswer;

function persistClassificationAnswer(\Laravel\Ai\Responses\Data\Answer $answer): void
{
    \Illuminate\Support\Facades\DB::select(
        'SELECT * FROM topics WHERE answer = ' . \serialize($answer),
    );
}

function queryFromClassificationAnswer(\Laravel\Ai\Responses\ClassificationResponse $response): void
{
    persistClassificationAnswer($response->answer('topic'));
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
