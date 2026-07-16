--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * The false-negative regression guard PR #1218 lacked. A keyed map whose value flows OUT via
 * `$conds['name']` into a raw SQL sink must KEEP its taint — the strip is scoped to where-family
 * argument nodes only, so an assignment/element-read here is untouched. #734
 */
function mapValueFlowsToRawSink(\Illuminate\Http\Request $request): void {
    $conds = ['name' => (string) $request->input('n')];
    \Illuminate\Support\Facades\DB::statement('select * from users where name = ' . $conds['name']);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
