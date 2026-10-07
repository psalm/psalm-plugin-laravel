--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/** HEX_TAG strips html only. Quotes need both HEX_QUOT and HEX_APOS. */
function renderJson(\Illuminate\Http\Request $request): void {
    $v = (string) $request->input('v');

    echo json_encode($v, JSON_HEX_TAG);
}

function renderJsonQuotOnly(\Illuminate\Http\Request $request): void {
    $v = (string) $request->input('v');

    echo json_encode($v, JSON_HEX_TAG | JSON_HEX_QUOT);
}

function renderJsonApos(\Illuminate\Http\Request $request): void {
    $v = (string) $request->input('v');

    echo json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS);
}

function renderUnionDropsBits(\Illuminate\Http\Request $request): void {
    $v = (string) $request->input('v');

    echo json_encode($v, $request->boolean('b') ? 15 : 9);
}

function renderUnionKeepsHtml(\Illuminate\Http\Request $request): void {
    $v = (string) $request->input('v');

    echo json_encode($v, $request->boolean('b') ? 15 : 14);
}
?>
--EXPECTF--
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
