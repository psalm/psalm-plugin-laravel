--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;

/**
 * Two successive `$request->validate([...])` calls on the same variable,
 * each covering a different field with a DIFFERENT escape bitmask. Proves
 * the cache keeps independent rule entries per field — a buggy
 * implementation that shared one entry across keys would fail at least
 * one assertion.
 *
 *   'count' -> 'integer' rule -> escapes ALL_INPUT (including HTML)
 *   'addr'  -> 'email' rule   -> escapes header/cookie only, preserves HTML
 *
 * Echoing 'count' is therefore safe; echoing 'addr' still reports
 * TaintedHtml because 'email' does not escape HTML. If the cache wrongly
 * shared 'count's ALL_INPUT escape with 'addr', this echo would be silently
 * clean — so the asserted TaintedHtml on the second echo is what proves
 * per-key isolation.
 */
/** @psalm-suppress MixedArgument */
function dumpMultiKeys(Request $request): void {
    $request->validate(['count' => 'required|integer']);
    $request->validate(['addr' => 'required|email']);

    // 'count' is integer-validated: all input taint kinds cleared,
    // echo is safe, no TaintedHtml.
    echo $request->input('count');

    // 'addr' is email-validated: only header/cookie cleared, HTML taint
    // preserved. Echo here must still report TaintedHtml. If the cache
    // wrongly shared 'count's ALL_INPUT escape with 'addr', this echo
    // would be silently clean — so this assertion guards per-key isolation.
    echo $request->input('addr');
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
