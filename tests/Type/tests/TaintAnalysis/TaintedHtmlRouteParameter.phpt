--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

function showRouteParam(\Illuminate\Routing\Route $route): void {
    echo $route->parameter('id');
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
PossiblyInvalidArgument on line %d: Argument 1 of echo expects string, but possibly different type null|object|string provided
