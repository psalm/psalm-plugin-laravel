--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Http\Client\Response::json() returns decoded JSON from an external HTTP
 * request — it must be treated as a taint source.
 */
function renderApiJson(\Illuminate\Http\Client\Response $response): void {
    echo $response->json('name');
}
?>
--EXPECTF--
MixedArgument on line %d: Argument 1 of echo cannot be mixed, expecting string
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
