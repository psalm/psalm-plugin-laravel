--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

class StaticNestedConditionArticle extends \Illuminate\Database\Eloquent\Model {}

/**
 * The direct static form `Model::where(...)` resolves through the pseudo-method (`__callStatic`)
 * path onto the same `Query\Builder::addArrayOfWheres()` stub sink as the instance form — a
 * `StaticCall` node, not a `MethodCall` on a `::query()` result (that shape is already covered by
 * SafeSqlEloquentWhereNestedCondition.phpt). Ordinal 2 is PDO-bound, so a tainted value must not be
 * flagged. #1300
 */
function safeStaticNestedConditionThreeElementForm(\Illuminate\Http\Request $request): void {
    $term = (string) $request->input('term');

    StaticNestedConditionArticle::where([['name', '=', $term]]);
}

/**
 * The 2-element form: the tainted ordinal-1 element lands in `$operator` first, is not a
 * recognised operator string, and Builder::invalidOperator() demotes it into `$value`, which is
 * PDO-bound. Same as SafeSqlWhereNestedConditionValues.phpt's two-element case, but via a direct
 * static call. #1300
 */
function safeStaticNestedConditionTwoElementForm(\Illuminate\Http\Request $request): void {
    $term = (string) $request->input('term');

    StaticNestedConditionArticle::where([['name', $term]]);
}
?>
--EXPECTF--
