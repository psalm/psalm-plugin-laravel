--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/** @psalm-suppress MixedAssignment, MixedArgument */
function unsafeWhereAny(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $columns = $request->input('columns');

    $builder->whereAny($columns, 'LIKE', '%test%');
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
