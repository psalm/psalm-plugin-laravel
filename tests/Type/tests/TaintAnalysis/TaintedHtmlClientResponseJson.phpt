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
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
