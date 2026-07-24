--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

trait TraitStaleWhereTrait {
    public static function callWhere(string $term): void {
        static::whereNot([['name', '=', $term]]);
    }
}

/**
 * A Model subclass, analysed FIRST: the trait method's `static::whereNot(...)` gate passes here,
 * so `beforeExpressionAnalysis` records ids against the trait's AST nodes.
 */
class TraitStaleModelUser extends \Illuminate\Database\Eloquent\Model {
    use TraitStaleWhereTrait;
}

/**
 * A non-Model class using the SAME trait, analysed SECOND. Psalm reanalyses a trait method once
 * per using class, reusing the SAME AST nodes each time — so without clearing the ids the first
 * pass recorded, this class's OWN `whereNot()` sink would wrongly stay stripped under the
 * leftover Model-context record. Uses `whereNot`, not `where()`, for the same batch-artifact
 * reason as TaintedSqlStaticWhereNestedConditionColumn.phpt — and STILL needs the separate
 * `--threads=1` ARGS group once several `whereNot`-based keep-taint tests share the default
 * group. #1300
 */
final class TraitStaleNonModelUser {
    use TraitStaleWhereTrait;

    /**
     * @psalm-taint-sink sql $parts
     */
    public static function whereNot(array $parts): void {}
}

function unsafeTraitReanalysisStaleIds(\Illuminate\Http\Request $request): void {
    $term = (string) $request->input('term');

    TraitStaleModelUser::callWhere($term);
    TraitStaleNonModelUser::callWhere($term);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
%ATaintedSql on line %d: Detected tainted SQL
