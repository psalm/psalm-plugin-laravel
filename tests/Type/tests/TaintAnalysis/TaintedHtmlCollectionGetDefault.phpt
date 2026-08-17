--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function renderCollectionGetDefault(\Illuminate\Http\Request $request): void {
    /** @var \Illuminate\Support\Collection<string, string> $collection */
    $collection = collect([]);
    echo $collection->get('key', $request->input('fallback'));
}
?>
--EXPECTF--
MixedArgument on line %d: Argument 1 of echo cannot be mixed|string, expecting string
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
