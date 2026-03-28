--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/** clamp() returns a numeric value constrained by min/max — cannot carry injection payload. */
function useClampInput(\Illuminate\Http\Request $request): void {
    echo $request->clamp('quantity', 1, 100);
}
?>
--EXPECTF--
