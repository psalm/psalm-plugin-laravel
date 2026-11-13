--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/** @psalm-taint-source html */
function taintedHtml(): string { return '<b>'; }

function reported(): void {
    echo value(fn () => taintedHtml());
    echo rescue(fn () => taintedHtml());
    echo once(fn () => taintedHtml());
    echo with('x', fn () => taintedHtml());
    echo retry(3, fn () => taintedHtml());
    echo \Illuminate\Support\Facades\Cache::rememberForever('k', fn () => taintedHtml());
    echo \Illuminate\Support\Facades\Cache::flexible('k', [10, 20], fn () => taintedHtml());
    echo \Illuminate\Support\Facades\DB::transaction(fn () => taintedHtml());
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
