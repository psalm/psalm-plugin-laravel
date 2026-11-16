--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;

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
    echo \Illuminate\Support\Facades\DB::transaction(fn (): int => 1);
}

// A supplied callback replaces `$value` in the result, so an escaping callback must not
// leave the raw input reaching the sink.
function sanitized(Request $request): void {
    echo with((string) $request->input('x'), fn (string $v): string => e($v));
}
?>
--EXPECTF--
