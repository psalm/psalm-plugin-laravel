--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function unsafeConnectionTable(\Illuminate\Http\Request $request): void {
    /** @var \Illuminate\Database\Connection $connection */
    $connection = new \Illuminate\Database\Connection(new \PDO('sqlite::memory:'));
    $table = $request->input('table');

    $connection->table($table);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
