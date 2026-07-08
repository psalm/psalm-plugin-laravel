--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * A tainted DYNAMIC key is a column identifier and must still flag. `[$tainted => 1]` has a
 * non-literal string key, so its type is `array<string, int>` (not a sealed TKeyedArray) and
 * isBoundValueMap rejects it — the sink stands. Uses orWhereNot, not where(): an upstream batch
 * artifact suppresses the where() variant when many taint tests run in one psalm process (see the
 * note in TaintedSqlWhereWholeArrayInput.phpt).
 *
 * @psalm-suppress TooFewArguments
 */
function unsafeTaintedKeyMap(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $column = (string) $request->input('column');

    $builder->orWhereNot([$column => 1]);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
