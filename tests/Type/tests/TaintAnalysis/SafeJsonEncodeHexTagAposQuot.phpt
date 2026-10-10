--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

final class JsonFlags
{
    public const HEX = \JSON_HEX_TAG | \JSON_HEX_QUOT | \JSON_HEX_APOS;
}

/**
 * Every site passes literal flags with JSON_HEX_TAG, JSON_HEX_APOS and JSON_HEX_QUOT (15 is the Blade
 * `@json` default), so neither TaintedHtml nor TaintedTextWithQuotes is reported.
 */
function renderJson(\Illuminate\Http\Request $request): void {
    $v = (string) $request->input('v');

    echo json_encode($v, 15);
    echo json_encode($v, 15, 512);
    echo json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
    echo \json_encode($v, \JSON_HEX_TAG | \JSON_HEX_QUOT | \JSON_HEX_APOS | \JSON_UNESCAPED_SLASHES);
    echo JSON_ENCODE($v, 15);
    echo json_encode($v, flags: 15);
    echo json_encode($v, depth: 512, flags: 15);

    $flags = 15;
    echo json_encode($v, $flags);

    echo json_encode($v, $request->boolean('b') ? 15 : 79);
    echo json_encode($v, JsonFlags::HEX);
}
?>
--EXPECTF--
