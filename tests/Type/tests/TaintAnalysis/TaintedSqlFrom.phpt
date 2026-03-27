--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function unsafeFrom(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $table = $request->input('table');

    $builder->from($table);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
