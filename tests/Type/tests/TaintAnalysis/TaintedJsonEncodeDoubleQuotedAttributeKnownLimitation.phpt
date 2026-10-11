--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * KNOWN LIMITATION: json_encode()'s own `"` delimiters close a double-quoted attribute, leaving the
 * payload unquoted (`v= onmouseover=alert(1) x=` renders `data-x="" onmouseover=alert(1) x=""`).
 * The HEX_APOS|HEX_QUOT strip still clears TaintedTextWithQuotes here; see the JsonEncodeTaintHandler
 * docblock for why the gap is accepted.
 */
function renderAttribute(\Illuminate\Http\Request $request): void {
    $v = (string) $request->input('v');

    echo '<div data-x="' . json_encode($v, 15 | JSON_THROW_ON_ERROR) . '"></div>';
}
?>
--EXPECTF--
