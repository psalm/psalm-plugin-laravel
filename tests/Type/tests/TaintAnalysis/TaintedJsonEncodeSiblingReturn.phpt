--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/**
 * Neither the handler nor core may strip a taint on the enclosing function's other return paths:
 * the call takes the raw `return $a;` path, so both html and has_quotes reach the echo.
 */
function first(string $a, bool $b): string|false {
    if ($b) {
        return json_encode($a, 15);
    }

    return $a;
}

function render(\Illuminate\Http\Request $request): void {
    echo first((string) $request->input('v'), false);
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
