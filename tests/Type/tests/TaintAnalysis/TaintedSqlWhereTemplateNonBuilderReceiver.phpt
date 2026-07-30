--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/**
 * The `TTemplateParam` accept-branch must actually verify its `as` bound is builder-only, not wave
 * any template receiver through: `@template T of <userland non-builder class>` must keep the sink
 * exactly like a concrete non-builder receiver already does (see
 * TaintedSqlWhereNonBuilderReceiver.phpt). If `isBuilderUnion` ever stopped recursing into a
 * template's `as` bound — e.g. treated every `TTemplateParam` as a builder without checking its
 * bound — this test goes silent.
 *
 * Runs in the `--threads=1` ARGS group; `DB::unprepared` is a distinct sink from the where-family
 * ones the default taint group saturates (see TaintedSqlWhereNonBuilderReceiver.phpt). #1336
 */
final class TemplateNonBuilderReportQuery {
    /** @param array{f: string} $parts */
    public function where(array $parts): void {
        \Illuminate\Support\Facades\DB::unprepared('select * from reports where ' . $parts['f']);
    }
}

/**
 * @template T of TemplateNonBuilderReportQuery
 * @param T $q
 */
function unsafeTemplateNonBuilderWhereReceiver(\Illuminate\Http\Request $request, $q): void {
    $q->where(['f' => (string) $request->input('x')]);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
