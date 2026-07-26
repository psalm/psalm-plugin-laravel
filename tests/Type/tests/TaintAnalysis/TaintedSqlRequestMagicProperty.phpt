--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function filterPosts(\Illuminate\Http\Request $request) {
    $builder = new \Illuminate\Database\Query\Builder();
    $builder->whereRaw((string) $request->filter);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
