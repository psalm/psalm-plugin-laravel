--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Ordinal 0 of a nested condition (`[[$column, '=', 'v']]`) is `$column` itself, wrapped as a raw
 * identifier by addArrayOfWheres — the element-wise strip only ever touches ordinals 1 and 2, so a
 * tainted column here must still flag. Uses whereNot, not where(): whereNot's array form still
 * delegates to addArrayOfWheres with identical dispatch (see Builder::whereNot()), but an upstream
 * batch artifact suppresses the where() variant when many taint tests run in one psalm process (see
 * the note in TaintedSqlWhereWholeArrayInput.phpt). #1300
 *
 * @psalm-suppress TooFewArguments
 */
function unsafeNestedConditionColumn(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $column = (string) $request->input('column');

    $builder->whereNot([[$column, '=', 'v']]);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
