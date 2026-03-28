--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function showUrl(\Illuminate\Http\Request $request): void {
    echo $request->fullUrlWithQuery(['page' => 2]);
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
