--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function unsafeInsertUsing(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $query = $request->input('query');

    $builder->insertUsing(['id', 'name'], $query);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
