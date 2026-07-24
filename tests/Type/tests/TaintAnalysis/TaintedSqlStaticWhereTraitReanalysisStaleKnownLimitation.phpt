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
 *
 * KNOWN LIMITATION (Psalm 6 only, this 3.x port): on master (Psalm 7) this scenario correctly
 * fires TWO TaintedSql findings — the plugin's per-node stale-id clearing (#1300) isolates each
 * reanalysis pass. On Psalm 6 the record/consume cycle for `beforeExpressionAnalysis` ->
 * `removeTaints` is NOT interleaved per reanalysis pass the same way: whichever using-class is
 * reanalysed LAST has its receiver-check result applied to BOTH passes, since both share the same
 * AST node identity (`spl_object_id`). Empirically confirmed order-dependent: swapping the
 * declaration order so the non-Model class is analysed last flips this from 0 findings to the
 * correct 2. This is a Psalm 6 trait-taint-reanalysis engine gap, not fixable from the plugin
 * side without keying on reanalysis-context the Psalm 6 event objects don't expose. Locking in
 * the degraded (0-finding) behaviour here so it's a deliberate, reviewed fact rather than a
 * silent regression — the non-trait-shared case (the common one) is unaffected, see
 * TaintedSqlStaticWhereNonModelReceiver.phpt.
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
