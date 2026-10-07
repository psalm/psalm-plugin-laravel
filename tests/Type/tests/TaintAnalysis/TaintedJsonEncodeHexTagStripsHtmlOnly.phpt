--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

final class JsonFlags
{
    public const HEX = \JSON_HEX_TAG | \JSON_HEX_QUOT | \JSON_HEX_APOS;
}

/**
 * Every site below passes literal flags the handler can prove contain HEX_TAG, so TaintedHtml is gone.
 * TaintedTextWithQuotes stays however many HEX flags are set: json_encode() always emits raw `"` delimiters,
 * so `<div data-x="<?= json_encode($v, 15) ?>">` is still an attribute breakout.
 */
function renderJson(\Illuminate\Http\Request $request): void {
    $v = (string) $request->input('v');

    echo json_encode($v, 15);
    echo json_encode($v, 15, 512);
    echo json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    echo \json_encode($v, \JSON_HEX_TAG | \JSON_HEX_QUOT | \JSON_HEX_APOS | \JSON_UNESCAPED_SLASHES);
    echo JSON_ENCODE($v, 15);
    echo json_encode($v, flags: 15);
    echo json_encode($v, depth: 512, flags: 15);

    $flags = 15;
    echo json_encode($v, $flags);

    // A union of literal sets proves only the bits common to every member.
    echo json_encode($v, $request->boolean('b') ? 15 : 79);

    echo json_encode($v, JSON_HEX_TAG);
    echo json_encode($v, JsonFlags::HEX);
}
?>
--EXPECTF--
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
