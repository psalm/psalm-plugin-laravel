--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/** integer() returns (int) cast of input — cannot carry injection payload. */
function useIntegerInput(\Illuminate\Http\Request $request): void {
    echo $request->integer('page');
}
?>
--EXPECTF--
