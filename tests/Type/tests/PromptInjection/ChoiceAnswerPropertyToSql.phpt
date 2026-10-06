--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\ChoiceAnswer;

function queryFromChoice(\Laravel\Ai\Responses\ClassificationResponse $response): void {
    // The choice is taken verbatim from the provider JSON and never validated
    // against the options the caller offered.
    $answer = $response->answer('topic');

    if ($answer instanceof \Laravel\Ai\Responses\Data\ChoiceAnswer) {
        \Illuminate\Support\Facades\DB::select('SELECT * FROM topics WHERE slug = ' . $answer->choice);
    }
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
