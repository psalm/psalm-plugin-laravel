--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/** The strip lives on the json_encode call's own edge, not on the encoded value: only `$v` keeps its taint. */
function render(\Illuminate\Http\Request $request): void {
    $v = (string) $request->input('v');

    $encoded = json_encode($v, 15);
    echo $encoded;
    echo $v;
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
