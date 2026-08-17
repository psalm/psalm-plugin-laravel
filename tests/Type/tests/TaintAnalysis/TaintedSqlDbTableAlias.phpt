--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function unsafeDbTableAlias(\Illuminate\Http\Request $request): void {
    $alias = $request->input('alias');

    \Illuminate\Support\Facades\DB::table('users', $alias);
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
