--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

// Cache helpers deliberately do not propagate their closure's taint: apps cache config and decrypted secrets
// far more often than request input, and propagating produced hundreds of false positives on real apps.
function show(\Illuminate\Http\Request $request): void {
    echo \Illuminate\Support\Facades\Cache::remember('k', 60, fn () => (string) $request->input('x'));
    echo \Illuminate\Support\Facades\Cache::rememberForever('k', fn () => (string) $request->input('x'));
    echo \Illuminate\Support\Facades\Cache::sear('k', fn () => (string) $request->input('x'));
    echo \Illuminate\Support\Facades\Cache::flexible('k', [10, 20], fn () => (string) $request->input('x'));
}
?>
--EXPECTF--
