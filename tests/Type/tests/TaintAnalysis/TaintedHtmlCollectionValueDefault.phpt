--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function renderCollectionValueDefault(\Illuminate\Http\Request $request): void {
    /** @var \Illuminate\Support\Collection<int, array{name: string}> $collection */
    $collection = collect([]);
    echo $collection->value('name', $request->input('fallback'));
}
?>
--EXPECTF--
MixedArgument on line %d: Argument 1 of echo cannot be mixed, expecting string
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
