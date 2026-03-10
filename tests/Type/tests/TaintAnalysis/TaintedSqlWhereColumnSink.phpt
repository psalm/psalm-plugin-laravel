--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function unsafeColumnWhere(\Illuminate\Http\Request $request) {
    $builder = new \Illuminate\Database\Query\Builder();
    $column = $request->input('column');

    $builder->where($column, 'safe-value');
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
