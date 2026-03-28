--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function unsafeConnectionTableAlias(\Illuminate\Http\Request $request): void {
    /** @var \Illuminate\Database\Connection $connection */
    $connection = app()->make(\Illuminate\Database\Connection::class);
    $alias = $request->input('alias');

    $connection->table('users', $alias);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
