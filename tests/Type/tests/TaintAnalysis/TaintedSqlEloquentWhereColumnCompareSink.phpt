--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

class EloquentWhereColumnCompareModel extends \Illuminate\Database\Eloquent\Model {}

/**
 * `whereColumn()` reaches Eloquent chains through `Eloquent\Builder`'s `@mixin
 * \Illuminate\Database\Query\Builder` (Eloquent/Builder.phpstub), so the plain Query\Builder sink
 * stub covers `Model::query()->whereColumn(...)` without a separate Eloquent-side stub. #1303
 */
function unsafeEloquentWhereColumn(\Illuminate\Http\Request $request): void {
    $tainted = (string) $request->input('col');

    EloquentWhereColumnCompareModel::query()->whereColumn($tainted, '=', 'x');
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
