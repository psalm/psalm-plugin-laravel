--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function unsafeSchemaRename(\Illuminate\Http\Request $request): void {
    /** @var \Illuminate\Database\Schema\Builder $schema */
    $schema = app()->make(\Illuminate\Database\Schema\Builder::class);
    $from = $request->input('from');

    $schema->rename($from, 'new_table');
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
