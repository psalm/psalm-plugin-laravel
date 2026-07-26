--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

class NestedConditionMagicPropertyArticle extends \Illuminate\Database\Eloquent\Model {}

/**
 * Interaction guard for #1301 x #1302, pinning #1300's exact third snippet: `request->term`
 * (a magic-property read) as the where()-array source instead of `request('term')` /
 * `$request->input('term')`. It reported no error at the time only because `__get` carried
 * no taint source at all — #1301 gives it one, so the ordinal-2 PDO-bound strip added by
 * #1302 must still apply when the source is a magic-property read, not just a method call.
 *
 * Same canary caveat as SafeSqlWhereNestedConditionValues.phpt: teeth-verified in an
 * isolated psalm process — both this shape (silent) and the same chain with the tainted
 * value moved to the ordinal-0 column position (still reports TaintedSql) — batch-swallowed
 * in the suite.
 */
function safeEloquentNestedConditionMagicPropertyWhere(): void {
    $term = (string) request()->term;

    NestedConditionMagicPropertyArticle::query()->select('id')->where([['name', 'LIKE', "%{$term}%"]])->get();
}
?>
--EXPECTF--
