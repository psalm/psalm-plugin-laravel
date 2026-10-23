--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * Facade path for the Schema\Builder sinks. Resolving through the facade's `@mixin Schema\Builder`
 * reaches the Builder stub's `@psalm-taint-sink sql $table`; the generated `@method` pseudo-methods
 * alone never reported these. Each call must report exactly once (no duplicate from both paths).
 */
function unsafeSchemaDrop(\Illuminate\Http\Request $request): void {
    Schema::drop((string) $request->input('table'));
}

function unsafeSchemaCreate(\Illuminate\Http\Request $request): void {
    Schema::create((string) $request->input('table'), static function (): void {});
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
