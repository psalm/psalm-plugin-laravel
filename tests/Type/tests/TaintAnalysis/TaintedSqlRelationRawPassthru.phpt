--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

// #1734: raw() is an Eloquent\Builder passthru; its sql sink must hold on this receiver.
function unsafeRaw(\Illuminate\Http\Request $request, \App\Models\Mechanic $mechanic): void {
    $input = (string) $request->input('expr');

    $mechanic->workOrders()->raw($input);
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
