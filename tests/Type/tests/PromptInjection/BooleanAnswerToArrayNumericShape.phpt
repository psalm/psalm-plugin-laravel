--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

function renderProbability(\Laravel\Ai\Responses\Data\BooleanAnswer $answer): void {
    // The only element is a native float, which cannot carry an injection payload.
    echo $answer->toArray()['probability'];
}
?>
--EXPECTF--
