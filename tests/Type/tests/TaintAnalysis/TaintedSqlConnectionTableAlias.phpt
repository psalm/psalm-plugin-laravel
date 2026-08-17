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
MixedAssignment on line %d: Unable to determine the type that $alias is being assigned to
MixedArgument on line %d: Argument 2 of Illuminate\Database\Connection::table cannot be mixed, expecting null|string
TaintedSql on line %d: Detected tainted SQL
