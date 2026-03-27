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
%ATaintedSql on line %d: Detected tainted SQL
