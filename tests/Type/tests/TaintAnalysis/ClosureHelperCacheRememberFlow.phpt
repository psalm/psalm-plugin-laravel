--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

// Cache::remember() returns what its closure returns, so the closure's taint must reach the echo.
function show(\Illuminate\Http\Request $request): void {
    echo \Illuminate\Support\Facades\Cache::remember('k', 60, fn () => (string) $request->input('x'));
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
