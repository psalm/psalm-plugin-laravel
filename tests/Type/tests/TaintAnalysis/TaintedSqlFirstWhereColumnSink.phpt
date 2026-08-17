--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

class FirstWhereColumnSinkModel extends \Illuminate\Database\Eloquent\Model {}

/**
 * String-form column injection through firstWhere() must still report (#733). firstWhere is a plain
 * method on Eloquent\Builder (vendor Eloquent/Builder.php:380) with its OWN `@psalm-taint-sink sql
 * $column` stub (Eloquent/Builder.phpstub:413) and its own WHERE_MAP_METHODS entry, so it is pinned
 * separately from where().
 */
function unsafeFirstWhereColumn(\Illuminate\Http\Request $request): void {
    $column = $request->input('column');

    FirstWhereColumnSinkModel::firstWhere($column, 'safe-value');
}
?>
--EXPECTF--
MixedAssignment on line %d: Unable to determine the type that $column is being assigned to
MixedArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::firstWhere cannot be mixed, expecting Illuminate\Contracts\Database\Query\Expression|array<array-key, mixed>|impure-Closure(Illuminate\Database\Eloquent\Builder<FirstWhereColumnSinkModel&static>):mixed|string
TaintedSql on line %d: Detected tainted SQL
