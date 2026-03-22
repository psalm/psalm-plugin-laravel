--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function renderSessionData(\Illuminate\Session\Store $session) {
    echo $session->get('user_input');
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
