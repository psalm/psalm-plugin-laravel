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
%ATaintedSql on line %d: Detected tainted SQL
