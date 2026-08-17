--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function showRouteParam(\Illuminate\Routing\Route $route) {
    echo $route->parameter('id');
}
?>
--EXPECTF--
MissingReturnType on line %d: Method showRouteParam does not have a return type, expecting void
PossiblyInvalidCast on line %d: object cannot be cast to string
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
