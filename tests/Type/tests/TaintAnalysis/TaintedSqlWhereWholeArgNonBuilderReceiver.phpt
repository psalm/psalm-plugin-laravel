--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/**
 * The WHOLE-ARGUMENT strip (`isBoundValueMap`) must gate on the receiver being a Laravel builder,
 * exactly like the element-wise strip already does via `isLaravelBuilder` — the method name
 * `where` alone does not mean `Builder::addArrayOfWheres()`. This is the whole-argument
 * counterpart to TaintedSqlWhereNonBuilderReceiver.phpt: the argument here is a CALL RESULT (not
 * an array literal), so `recordBoundValuePositions` never runs and only the whole-argument path
 * is exercised.
 *
 * Runs in the `--threads=1` ARGS group; `DB::unprepared` is a distinct sink from the where-family
 * ones the default taint group saturates (see TaintedSqlWhereNonBuilderReceiver.phpt). #1306
 */
final class NonBuilderWholeArgQuery {
    /** @param array{name: string} $conditions */
    public function where(array $conditions): void {
        \Illuminate\Support\Facades\DB::unprepared('select * from t where name = ' . $conditions['name']);
    }
}

/** @return array{name: string} */
function wholeArgTaintedConditions(\Illuminate\Http\Request $request): array {
    return ['name' => (string) $request->input('name')];
}

function unsafeNonBuilderWholeArgWhere(\Illuminate\Http\Request $request): void {
    (new NonBuilderWholeArgQuery())->where(wholeArgTaintedConditions($request));
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
