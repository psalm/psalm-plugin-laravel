--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Http\Client\Response::headers() returns all response headers from an external
 * HTTP response — it must be treated as a taint source.
 */
function renderApiHeaders(\Illuminate\Http\Client\Response $response): void {
    echo $response->headers()['Content-Type'];
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
