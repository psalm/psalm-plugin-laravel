--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function unsafeSchemaRenameTo(\Illuminate\Http\Request $request): void {
    /** @var \Illuminate\Database\Schema\Builder $schema */
    $schema = app()->make(\Illuminate\Database\Schema\Builder::class);
    $to = $request->input('to');

    $schema->rename('old_table', $to);
}
?>
--EXPECTF--
MixedAssignment on line %d: Unable to determine the type that $to is being assigned to
MixedArgument on line %d: Argument 2 of Illuminate\Database\Schema\Builder::rename cannot be mixed, expecting string
TaintedSql on line %d: Detected tainted SQL
