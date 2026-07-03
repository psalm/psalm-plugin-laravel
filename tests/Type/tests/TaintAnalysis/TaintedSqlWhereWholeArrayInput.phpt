--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * where($request->all()) is NOT the safe keyed-literal form: the array KEYS become column
 * identifiers and here they are entirely user-controlled, so this must still be flagged.
 * This is why the safe gate is a keyed-array literal (TKeyedArray), not is_array().
 *
 * @psalm-suppress TooFewArguments
 */
function unsafeWholeArrayWhere(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();

    $builder->where($request->all());
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
