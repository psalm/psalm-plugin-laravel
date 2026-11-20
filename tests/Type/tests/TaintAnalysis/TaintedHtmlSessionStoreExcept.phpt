--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

function storeProfile(\Illuminate\Http\Request $request, \Illuminate\Session\Store $session): void {
    $session->put(['name' => $request->input('name'), 'password' => 'secret']);
}

function renderExceptSessionData(\Illuminate\Session\Store $session): void {
    $data = $session->except(['password']);

    echo $data['name'];
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
MixedArgument on line %d: Argument 1 of echo cannot be mixed, expecting string
PossiblyUndefinedStringArrayOffset on line %d: Possibly undefined array offset ''name'' is risky given expected type 'array-key'. Consider using isset beforehand.
