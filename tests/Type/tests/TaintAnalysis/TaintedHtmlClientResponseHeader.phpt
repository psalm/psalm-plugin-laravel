--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Http\Client\Response::header() returns a header value from an external HTTP
 * response — it must be treated as a taint source.
 */
function renderApiHeader(\Illuminate\Http\Client\Response $response): void {
    echo $response->header('X-Custom');
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
