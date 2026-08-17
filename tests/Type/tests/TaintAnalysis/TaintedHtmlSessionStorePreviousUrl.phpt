--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function renderPreviousUrl(\Illuminate\Session\Store $session) {
    echo $session->previousUrl();
}
?>
--EXPECTF--
MissingReturnType on line %d: Method renderPreviousUrl does not have a return type, expecting void
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
