--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * A keyed map returned from a function and then interpolated into a raw SQL sink must keep its taint.
 * PR #1218's unscoped strip removed `sql` from every sealed string-key map (including this return
 * expression), a false negative; the scoped strip fires only on where-family argument nodes. #734
 *
 * @return array{clause: string}
 */
function buildFilter(\Illuminate\Http\Request $request): array {
    return ['clause' => (string) $request->input('x')];
}

function useBuiltFilter(\Illuminate\Http\Request $request): void {
    \Illuminate\Support\Facades\DB::statement('select * from t where ' . buildFilter($request)['clause']);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
