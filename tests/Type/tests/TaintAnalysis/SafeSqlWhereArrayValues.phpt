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

/**
 * orWhere map form — a distinct WHERE_MAP_METHODS entry, so it gets its own coverage (a typo in the
 * allowlist would otherwise ship unnoticed).
 *
 * @psalm-suppress TooFewArguments, MixedAssignment
 */
function safeArrayColumnOrWhere(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $status = $request->input('status');

    $builder->orWhere(['status_id' => $status]);
}

/**
 * whereNot map form — another distinct WHERE_MAP_METHODS entry, pinned so dropping 'wherenot' from the
 * allowlist cannot stay green.
 *
 * @psalm-suppress TooFewArguments, MixedAssignment
 */
function safeArrayColumnWhereNot(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $status = $request->input('status');

    $builder->whereNot(['status_id' => $status]);
}
?>
--EXPECTF--
