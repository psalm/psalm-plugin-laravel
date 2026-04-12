--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Http\Client\Response::object() returns decoded JSON as an object from an
 * external HTTP response — it must be treated as a taint source.
 */
function renderApiObject(\Illuminate\Http\Client\Response $response): void {
    echo json_encode($response->object());
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
