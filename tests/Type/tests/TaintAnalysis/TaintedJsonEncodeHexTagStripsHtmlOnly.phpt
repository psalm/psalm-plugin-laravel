--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * JSON_HEX_TAG proves no `<` or `>`, so TaintedHtml is gone. TaintedTextWithQuotes stays unless both
 * JSON_HEX_APOS and JSON_HEX_QUOT are set: without HEX_QUOT a payload `"` encodes as `\"`, and a
 * backslash escapes nothing in HTML.
 */
function renderJson(\Illuminate\Http\Request $request): void {
    $v = (string) $request->input('v');

    echo json_encode($v, JSON_HEX_TAG);
    echo json_encode($v, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES);
    echo json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS);
    echo json_encode($v, JSON_HEX_TAG | JSON_HEX_QUOT);

    // A union of literal sets proves only the bits common to every member: 15 & 9 lacks HEX_APOS.
    echo json_encode($v, $request->boolean('b') ? 15 : 9);
}
?>
--EXPECTF--
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
