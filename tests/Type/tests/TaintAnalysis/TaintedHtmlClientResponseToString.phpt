--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Http\Client\Response::__toString() returns the response body via string
 * casting — it must be treated as a taint source.
 */
function renderApiResponse(\Illuminate\Http\Client\Response $response): void {
    echo (string) $response;
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
