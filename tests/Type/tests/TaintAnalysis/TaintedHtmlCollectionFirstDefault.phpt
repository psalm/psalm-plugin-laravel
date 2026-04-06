--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function renderCollectionFirstDefault(\Illuminate\Http\Request $request): void {
    /** @var \Illuminate\Support\Collection<int, string> $collection */
    $collection = collect([]);
    echo $collection->first(null, $request->input('fallback'));
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
