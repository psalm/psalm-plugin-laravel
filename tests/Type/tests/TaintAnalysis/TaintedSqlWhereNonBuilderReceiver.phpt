--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/**
 * The element-wise strip mirrors `Illuminate\Database\Query\Builder::addArrayOfWheres()`, so it may
 * only fire when the receiver actually IS a Laravel builder. A project's own class exposing a
 * `where(array $parts)` that interpolates an element into raw SQL must keep the sink — the method
 * name alone means nothing.
 *
 * Runs in the `--threads=1` ARGS group; `DB::unprepared` is a distinct sink from the where-family
 * ones the default taint group saturates. #1300
 */
final class NonBuilderReportQuery {
    /** @param array<array-key, string> $parts */
    public function where(array $parts): void {
        \Illuminate\Support\Facades\DB::unprepared('select * from reports where ' . $parts[0]);
    }
}

function unsafeNonBuilderWhereReceiver(\Illuminate\Http\Request $request): void {
    (new NonBuilderReportQuery())->where([(string) $request->input('clause')]);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
