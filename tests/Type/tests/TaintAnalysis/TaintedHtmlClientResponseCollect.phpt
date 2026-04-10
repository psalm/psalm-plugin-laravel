--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Http\Client\Response::collect() returns decoded JSON as a Collection from an
 * external HTTP response — it must be treated as a taint source.
 */
function renderApiCollect(\Illuminate\Http\Client\Response $response): void {
    echo json_encode($response->collect());
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
