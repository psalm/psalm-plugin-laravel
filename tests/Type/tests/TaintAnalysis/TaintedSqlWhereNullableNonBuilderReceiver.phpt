--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/**
 * Tolerating the `TNull` atomic in the receiver gate must not let a NULLABLE non-builder receiver
 * through: `isLaravelBuilder` still requires every non-null atomic to be a Laravel builder, so a
 * project's own class must keep the sink even when its declared type is nullable. #1336
 *
 * Runs in the `--threads=1` ARGS group; `DB::unprepared` is a distinct sink from the where-family
 * ones the default taint group saturates (see TaintedSqlWhereNonBuilderReceiver.phpt).
 */
final class NullableNonBuilderReportQuery {
    /** @param array<array-key, string> $parts */
    public function where(array $parts): void {
        \Illuminate\Support\Facades\DB::unprepared('select * from reports where ' . $parts[0]);
    }
}

/** @psalm-suppress PossiblyNullReference */
function unsafeNullableNonBuilderWhereReceiver(\Illuminate\Http\Request $request, ?NullableNonBuilderReportQuery $q): void {
    $q->where([(string) $request->input('clause')]);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
