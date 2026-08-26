--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * Facade path: Schema::hasTable() resolves via the concrete facade stub after
 * FacadeStubPrecedenceHandler removes Laravel's shadowing pseudo-method. Verifies that
 * the concrete method supplies both the widened type and @psalm-taint-sink annotation.
 *
 * Builder variant lives in TaintedSqlSchemaBuilderHasTable.phpt.
 */
function unsafeSchemaHasTable(\Illuminate\Http\Request $request): bool {
    $table = $request->input('table');

    return Schema::hasTable($table);
}
?>
--EXPECTF--
MixedAssignment on line %d: Unable to determine the type that $table is being assigned to
MixedArgument on line %d: Argument 1 of Illuminate\Support\Facades\Schema::hasTable cannot be mixed, expecting Stringable|string
TaintedSql on line %d: Detected tainted SQL
