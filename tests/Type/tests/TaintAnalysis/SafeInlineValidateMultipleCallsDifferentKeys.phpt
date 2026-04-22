--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;

/**
 * Two successive `$request->validate([...])` calls on the same variable,
 * each covering a different field with a DIFFERENT escape bitmask. This
 * proves the cache keeps independent rule entries per field — a buggy
 * implementation that shared one entry across keys would fail at least
 * one assertion.
 *
 *   'count' -> 'integer' rule  -> escapes ALL_INPUT (including HTML)
 *   'slug'  -> 'alpha' rule    -> escapes ALL_INPUT too, but the test
 *                                  deliberately uses it AFTER switching
 *                                  one key to a narrower-escape rule so
 *                                  the bitmasks genuinely differ.
 *
 * Wait, to differentiate clearly: use 'count' => 'integer' (ALL_INPUT)
 * and 'addr' => 'email' (header/cookie only). Echoing the 'count' value
 * is safe; echoing 'addr' must still report TaintedHtml because 'email'
 * preserves HTML taint.
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
