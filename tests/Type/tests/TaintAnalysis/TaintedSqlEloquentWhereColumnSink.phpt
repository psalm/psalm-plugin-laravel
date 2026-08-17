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
MixedAssignment on line %d: Unable to determine the type that $column is being assigned to
MixedArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::where cannot be mixed, expecting Illuminate\Contracts\Database\Query\Expression|array<array-key, mixed>|impure-Closure(Illuminate\Database\Eloquent\Builder<EloquentColumnSinkPost&static>):mixed|string
TaintedSql on line %d: Detected tainted SQL
