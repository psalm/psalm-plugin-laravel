--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/** @psalm-suppress MixedAssignment, MixedArgument */
function unsafeInsertUsingColumns(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $columns = $request->input('columns');

    $builder->insertUsing($columns, 'SELECT id, name FROM other_table');
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
