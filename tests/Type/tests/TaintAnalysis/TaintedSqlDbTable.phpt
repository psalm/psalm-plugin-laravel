--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function unsafeDbTable(\Illuminate\Http\Request $request): void {
    $table = $request->input('table');

    \Illuminate\Support\Facades\DB::table($table);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
