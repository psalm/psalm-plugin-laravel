--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/** float() returns (float) cast of input — cannot carry injection payload. */
function useFloatInput(\Illuminate\Http\Request $request): void {
    echo $request->float('price');
}
?>
--EXPECTF--
