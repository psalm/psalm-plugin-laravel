--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

// #1734: raw() is an Eloquent\Builder passthru; its sql sink must hold on this receiver.
function unsafeRaw(\Illuminate\Http\Request $request): void {
    $input = (string) $request->input('expr');

    \App\Models\WorkOrder::query()->raw($input);
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
