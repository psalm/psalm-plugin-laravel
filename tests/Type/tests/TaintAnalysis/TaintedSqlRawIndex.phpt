--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function unsafeRawIndex(\Illuminate\Http\Request $request): void {
    /** @var \Illuminate\Database\Schema\Builder $schema */
    $schema = app()->make(\Illuminate\Database\Schema\Builder::class);
    $expression = $request->input('expression');

    $schema->table('users', function (\Illuminate\Database\Schema\Blueprint $blueprint) use ($expression) {
        $blueprint->rawIndex($expression, 'my_index');
    });
}
?>
--EXPECTF--
MixedAssignment on line %d: Unable to determine the type that $expression is being assigned to
MixedArgument on line %d: Argument 1 of Illuminate\Database\Schema\Blueprint::rawIndex cannot be mixed, expecting string
TaintedSql on line %d: Detected tainted SQL
