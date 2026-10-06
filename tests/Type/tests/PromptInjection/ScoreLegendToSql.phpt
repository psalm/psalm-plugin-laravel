--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\ScoreLegend;

function queryFromLegend(\Laravel\Ai\Responses\Data\ScoreAnswer $answer): void {
    // The provider may echo its own legend instead of the caller's levels.
    \Illuminate\Support\Facades\DB::select('SELECT * FROM levels WHERE legend = ' . \serialize($answer->legend));
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
