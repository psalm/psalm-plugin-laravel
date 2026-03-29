--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function displayLiteral(): void {
    echo \Illuminate\Support\Str::of('safe literal');
    echo str('also safe');
}
?>
--EXPECTF--
