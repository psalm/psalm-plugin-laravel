--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

namespace Issue1339NonBuilder;

/**
 * A template intersection without any Builder ancestry must not strip its map-form taint merely
 * because it has an extra type. The userland `where()` implementation flows that value to raw SQL.
 * This expectation is exact, so an omitted non-Builder report cannot be masked. #1339
 */
final class NonBuilderReportQuery {
    /** @param array{name: string} $conditions */
    public function where(array $conditions): void {
        \Illuminate\Support\Facades\DB::unprepared('select * from reports where name = ' . $conditions['name']);
    }
}

/**
 * @template T of NonBuilderReportQuery
 * @param T&NonBuilderReportQuery $query
 */
function unsafeTemplateIntersectionWhere(\Illuminate\Http\Request $request, $query): void {
    $query->where(['name' => (string) $request->input('name')]);
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
