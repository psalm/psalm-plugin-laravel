--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * The handler must not strip has_quotes on the enclosing function's other return paths:
 * Psalm accumulates removed taints of every return expression into the function storage.
 * TaintedHtml is lost here by core's own json_encode() html removal, which has no such guard.
 *
 * @todo-by https://github.com/vimeo/psalm/issues/12470 expect TaintedHtml again (removal no longer leaks into later returns)
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
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
