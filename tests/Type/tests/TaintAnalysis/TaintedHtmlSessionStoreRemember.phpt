--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

function storeSessionData(\Illuminate\Http\Request $request, \Illuminate\Session\Store $session): void {
    $session->flash('user_input', $request->input('user_input'));
}

function renderRememberedSessionData(\Illuminate\Session\Store $session): void {
    echo $session->remember('user_input', fn () => 'default');
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
MixedArgument on line %d: Argument 1 of echo cannot be mixed, expecting string
