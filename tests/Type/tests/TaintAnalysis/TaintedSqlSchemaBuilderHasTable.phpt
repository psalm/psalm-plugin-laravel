--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Direct Builder::hasTable() call path. Exercises the `@psalm-taint-sink sql $table`
 * annotation on the Builder stub (stubs/common/Database/Schema/Builder.phpstub). Mirrors
 * sibling TaintedSqlSchemaDrop / Rename / Table tests in this directory.
 *
 * Facade variant lives in TaintedSqlSchemaHasTable.phpt.
 */
function unsafeSchemaBuilderHasTable(\Illuminate\Http\Request $request): bool {
    /** @var \Illuminate\Database\Schema\Builder $schema */
    $schema = app()->make(\Illuminate\Database\Schema\Builder::class);
    $table = $request->input('table');

    return $schema->hasTable($table);
}
?>
--EXPECTF--
MixedAssignment on line %d: Unable to determine the type that $table is being assigned to
MixedArgument on line %d: Argument 1 of Illuminate\Database\Schema\Builder::hasTable cannot be mixed, expecting Stringable|string
TaintedSql on line %d: Detected tainted SQL
