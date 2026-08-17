--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function renderRememberedSessionData(\Illuminate\Session\Store $session) {
    echo $session->remember('user_input', fn () => 'default');
}
?>
--EXPECTF--
MissingReturnType on line %d: Method renderRememberedSessionData does not have a return type, expecting void
MixedArgument on line %d: Argument 1 of echo cannot be mixed, expecting string
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
