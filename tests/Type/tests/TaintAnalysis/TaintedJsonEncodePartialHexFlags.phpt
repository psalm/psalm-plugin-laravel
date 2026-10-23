--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Each taint needs its own flag set. html: JSON_HEX_TAG | JSON_HEX_AMP as one literal (core). has_quotes:
 * JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP, AND-ed across a literal union (plugin). Without HEX_AMP an
 * attribute decodes a payload `&lt;` or `&quot;` back to `<` or `"`.
 */
function renderJson(\Illuminate\Http\Request $request): void {
    $v = (string) $request->input('v');

    // html cleared, quotes kept
    echo json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP);
    echo json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT);

    // both kept: no HEX_AMP
    echo json_encode($v, JSON_HEX_TAG);
    echo json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);

    // quotes cleared, html kept: no HEX_TAG
    echo json_encode($v, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

    // quotes cleared (15 & 79 = 15), html kept: core needs a single literal
    echo json_encode($v, $request->boolean('b') ? 15 : 79);
}
?>
--EXPECTF--
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
