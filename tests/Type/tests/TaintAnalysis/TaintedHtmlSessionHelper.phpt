--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function renderSessionData() {
    echo session('last_query');
}
?>
--EXPECTF--
MissingReturnType on line %d: Method renderSessionData does not have a return type, expecting void
MixedArgument on line %d: Argument 1 of echo cannot be mixed, expecting string
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
