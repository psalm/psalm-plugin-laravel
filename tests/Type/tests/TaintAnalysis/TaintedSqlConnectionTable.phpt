--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function unsafeConnectionTable(\Illuminate\Http\Request $request): void {
    /** @var \Illuminate\Database\Connection $connection */
    $connection = app()->make(\Illuminate\Database\Connection::class);
    $table = $request->input('table');

    $connection->table($table);
}
?>
--EXPECTF--
MixedAssignment on line %d: Unable to determine the type that $table is being assigned to
MixedArgument on line %d: Argument 1 of Illuminate\Database\Connection::table cannot be mixed, expecting Illuminate\Contracts\Database\Query\Expression|Illuminate\Database\Query\Builder|UnitEnum|impure-Closure|string
TaintedSql on line %d: Detected tainted SQL
