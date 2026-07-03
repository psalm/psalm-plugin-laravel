--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

class FirstWhereColumnSinkModel extends \Illuminate\Database\Eloquent\Model {}

/**
 * String-form column injection through firstWhere() must still report (#733). firstWhere resolves via
 * BuildsQueries, a different path than where(), so it is pinned separately.
 */
function unsafeFirstWhereColumn(\Illuminate\Http\Request $request): void {
    $column = $request->input('column');

    FirstWhereColumnSinkModel::firstWhere($column, 'safe-value');
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
