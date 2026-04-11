--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

class EloquentColumnSinkPost extends \Illuminate\Database\Eloquent\Model {}

function unsafeEloquentColumnWhere(\Illuminate\Http\Request $request): void {
    $column = $request->input('column');

    EloquentColumnSinkPost::where($column, 'safe-value');
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
