--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * where(['col' => $value]) routes through addArrayOfWheres(), which binds each value as a
 * PDO parameter (where($key, '=', $value)). A tainted value is never interpolated as a column
 * identifier, so the keyed-array form must not be flagged. Regression guard for #734.
 *
 * @psalm-suppress TooFewArguments, MixedAssignment
 */
function safeArrayColumnWhere(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $status = $request->input('status');

    $builder->where(['status_id' => $status, 'to_id' => 1]);
}
?>
--EXPECTF--
