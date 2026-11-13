--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/** @psalm-taint-source html */
function taintedHtml(): string { return '<b>'; }

// A closure returning a literal or an int carries no taint, and a tainted value
// at another call site must not leak into it (the flows are call-site specialized).
function clean(): void {
    taintedHtml();
    echo value(fn () => 'safe');
    echo rescue(fn () => 'safe');
    echo once(fn () => 'safe');
    echo retry(3, fn (): int => 1);
    echo \Illuminate\Support\Facades\Cache::remember('k', 60, fn () => 'safe');
    echo \Illuminate\Support\Facades\DB::transaction(fn (): int => 1);
}
?>
--EXPECTF--
