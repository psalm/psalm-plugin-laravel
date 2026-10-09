--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;
use App\Models\WorkOrder;
use Illuminate\Http\Request;

// #1734: Eloquent\Builder re-declares these $passthru methods, shadowing the Query\Builder
// mixin, so each sql sink must be declared on Eloquent\Builder too. One case per sink,
// on an Eloquent\Builder receiver and on a relation receiver.

function eloquentInsertUsingColumns(Request $request): void {
    WorkOrder::query()->insertUsing([(string) $request->input('c')], 'SELECT id FROM t');
}

function eloquentInsertUsingQuery(Request $request): void {
    WorkOrder::query()->insertUsing(['id'], (string) $request->input('q'));
}

function eloquentInsertOrIgnoreUsingColumns(Request $request): void {
    WorkOrder::query()->insertOrIgnoreUsing([(string) $request->input('c')], 'SELECT id FROM t');
}

function eloquentInsertOrIgnoreUsingQuery(Request $request): void {
    WorkOrder::query()->insertOrIgnoreUsing(['id'], (string) $request->input('q'));
}

function eloquentRawValue(Request $request): void {
    WorkOrder::query()->rawValue((string) $request->input('e'));
}

function relationInsertUsingQuery(Request $request, Mechanic $mechanic): void {
    $mechanic->workOrders()->insertUsing(['id'], (string) $request->input('q'));
}

function relationInsertOrIgnoreUsingQuery(Request $request, Mechanic $mechanic): void {
    $mechanic->workOrders()->insertOrIgnoreUsing(['id'], (string) $request->input('q'));
}

function relationRawValue(Request $request, Mechanic $mechanic): void {
    $mechanic->workOrders()->rawValue((string) $request->input('e'));
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
