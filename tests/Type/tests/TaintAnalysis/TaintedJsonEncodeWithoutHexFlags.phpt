--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function noFlags(\Illuminate\Http\Request $request): void {
    $v = (string) $request->input('v');

    echo json_encode($v);
}

function unrelatedFlag(\Illuminate\Http\Request $request): void {
    $v = (string) $request->input('v');

    echo json_encode($v, JSON_UNESCAPED_SLASHES);
}

function dynamicFlags(\Illuminate\Http\Request $request): void {
    $v = (string) $request->input('v');

    echo json_encode($v, (int) $request->input('flags'));
}

/** A deliberately pinned false positive: HEX_TAG is always set at runtime here, but the value is not a literal. */
function nonLiteralOr(\Illuminate\Http\Request $request): void {
    $v = (string) $request->input('v');

    echo json_encode($v, (int) $request->input('flags') | JSON_HEX_TAG);
}

function spreadFlagsLiteral(\Illuminate\Http\Request $request): void {
    $args = [(string) $request->input('v'), 15];
    echo json_encode(...$args);
}

function spreadWithNamedFlags(\Illuminate\Http\Request $request): void {
    $args = [(string) $request->input('v')];
    echo json_encode(...$args, flags: 15);
}

/** Every possible value must carry the flag: 0 does not, so the union proves nothing. */
function unionWithoutHexTag(\Illuminate\Http\Request $request): void {
    $v = (string) $request->input('v');

    echo json_encode($v, $request->boolean('b') ? 15 : 0);
}

/** Not a json_encode-named call: the invocation goes through an expression. */
function firstClassCallable(\Illuminate\Http\Request $request): void {
    $encode = json_encode(...);
    echo $encode((string) $request->input('v'), 15);
}

/** Quote flags never clear html: APOS|QUOT keeps only TaintedHtml, QUOT alone keeps both. */
function quoteFlagsWithoutHexTag(\Illuminate\Http\Request $request): void {
    $v = (string) $request->input('v');

    echo json_encode($v, JSON_HEX_APOS | JSON_HEX_QUOT);
    echo json_encode($v, JSON_HEX_QUOT);
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
