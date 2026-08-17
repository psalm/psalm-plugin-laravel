--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function showOriginalParams(\Illuminate\Routing\Route $route): void {
    $params = $route->originalParameters();
    echo $params['id'];
}
?>
--EXPECTF--
MixedArgument on line %d: Argument 1 of echo cannot be mixed, expecting string
PossiblyUndefinedStringArrayOffset on line %d: Possibly undefined array offset ''id'' is risky given expected type 'array-key'. Consider using isset beforehand.
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
