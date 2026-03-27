--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function unsafeOrderBy(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $column = $request->input('sort');

    $builder->orderBy($column);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
