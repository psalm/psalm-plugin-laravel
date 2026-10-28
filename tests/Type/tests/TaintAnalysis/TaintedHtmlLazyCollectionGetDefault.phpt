--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

function renderLazyCollectionGetDefault(\Illuminate\Http\Request $request): void {
    /** @var \Illuminate\Support\LazyCollection<string, string> $collection */
    $collection = new \Illuminate\Support\LazyCollection([]);
    echo $collection->get('key', $request->input('fallback'));
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
MixedArgument on line %d: Argument 1 of echo cannot be mixed|string, expecting string
