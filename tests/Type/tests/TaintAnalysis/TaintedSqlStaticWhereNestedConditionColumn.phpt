--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

class StaticNestedConditionColumnArticle extends \Illuminate\Database\Eloquent\Model {}

/**
 * Ordinal 0 of a nested condition is `$column` itself, wrapped as a raw identifier by
 * `addArrayOfWheres` — the element-wise strip only ever touches ordinals 1 and 2, so a tainted
 * column must still flag through the direct static `Model::where(...)` form too, mirroring
 * TaintedSqlWhereNestedConditionColumn.phpt's instance-receiver case. Uses `whereNot`, not
 * `where()`, for the same reason as that sibling test: an upstream batch artifact suppresses the
 * `where()` variant once many taint tests share one psalm process (see the note in
 * TaintedSqlWhereWholeArrayInput.phpt); `whereNot`'s array form still delegates to
 * `addArrayOfWheres` with identical dispatch. #1300
 */
function unsafeStaticNestedConditionColumn(\Illuminate\Http\Request $request): void {
    $column = (string) $request->input('column');

    StaticNestedConditionColumnArticle::whereNot([[$column, '=', 'v']]);
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
