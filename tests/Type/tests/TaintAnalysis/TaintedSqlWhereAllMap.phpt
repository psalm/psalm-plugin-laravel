--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * `whereAll` is NOT in the strip's method allowlist: the values of a whereAll array are COLUMN
 * identifiers, not bound values, so `whereAll(['status_id' => $tainted])` must keep flagging. This is
 * the name-exclusion soundness win over PR #1218's method-agnostic strip. #734
 *
 * @psalm-suppress TooFewArguments
 */
function whereAllKeyedMapFlags(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $column = (string) $request->input('column');

    $builder->whereAll(['status_id' => $column]);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
