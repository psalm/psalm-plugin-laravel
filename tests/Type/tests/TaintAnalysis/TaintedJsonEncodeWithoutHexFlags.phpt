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
