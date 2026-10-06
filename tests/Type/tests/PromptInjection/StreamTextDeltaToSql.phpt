--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace App\StreamDelta;

function queryFromStreamedTokens(\Laravel\Ai\Responses\StreamableAgentResponse $stream): void {
    foreach ($stream as $event) {
        if ($event instanceof \Laravel\Ai\Streaming\Events\TextDelta) {
            \Illuminate\Support\Facades\DB::select('SELECT * FROM notes WHERE body = ' . $event->delta);
        }
    }
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
