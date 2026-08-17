--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function showSearchResults(\Illuminate\Http\Request $request) {
    echo $request->query('q');
}
?>
--EXPECTF--
MissingReturnType on line %d: Method showSearchResults does not have a return type, expecting void
PossiblyInvalidArgument on line %d: Argument 1 of echo expects string, but possibly different type array<array-key, mixed>|null|string provided
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
