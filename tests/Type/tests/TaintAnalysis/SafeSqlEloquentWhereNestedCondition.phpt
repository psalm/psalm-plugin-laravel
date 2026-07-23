--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

class NestedConditionArticle extends \Illuminate\Database\Eloquent\Model {}

/**
 * Pins the Eloquent side of #1300's exact repro: the static builder form reaches the same
 * Query\Builder::where() stub through `@mixin`, so the nested-condition value must stay unflagged
 * there too, not just on a bare Query\Builder instance.
 *
 * Same canary caveat as SafeSqlWhereNestedConditionValues.phpt: teeth-verified in an isolated psalm
 * process, batch-swallowed in the suite.
 */
function safeEloquentNestedConditionWhere(\Illuminate\Http\Request $request): void {
    $term = (string) $request->input('term');

    NestedConditionArticle::query()->select('id')->where([['name', 'LIKE', "%{$term}%"]])->get();
}
?>
--EXPECTF--
