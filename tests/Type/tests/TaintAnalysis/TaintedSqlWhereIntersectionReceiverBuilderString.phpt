--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

namespace Issue1339BuilderString;

/**
 * Builder proof through `extra_types` applies only to PDO-bound map values. Its string-form
 * `$column` remains a raw identifier sink. This exact expectation prevents the map-form strip from
 * expanding to the string overload. #1339
 *
 * @template T of \Illuminate\Contracts\Database\Query\Builder
 * @param T&\Illuminate\Database\Query\Builder $query
 */
function unsafeTemplateIntersectionBuilderStringWhere(\Illuminate\Http\Request $request, $query): void {
    $query->where((string) $request->input('name'));
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
