--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function unsafeSchemaCreate(\Illuminate\Http\Request $request): void {
    /** @var \Illuminate\Database\Schema\Builder $schema */
    $schema = app()->make(\Illuminate\Database\Schema\Builder::class);
    $table = $request->input('table');

    $schema->create($table, function (\Illuminate\Database\Schema\Blueprint $blueprint) {
        $blueprint->id();
    });
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
