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
MixedAssignment on line %d: Unable to determine the type that $alias is being assigned to
MixedArgument on line %d: Argument 2 of Illuminate\Support\Facades\DB::table cannot be mixed, expecting null|string
TaintedSql on line %d: Detected tainted SQL
