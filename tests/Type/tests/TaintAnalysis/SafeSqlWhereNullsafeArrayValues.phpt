--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Upstream-behavior CANARY, not a pin. Psalm does not currently dispatch the `sql` sink through a
 * nullsafe call `$builder?->where([...])`, so this stays green even with the handler disabled. The
 * Before-hook still handles the NullsafeMethodCall branch, so if a future Psalm starts flowing sinks
 * through nullsafe calls this test catches the resulting map-form FP before it reaches users.
 */
function safeNullsafeArrayWhere(\Illuminate\Http\Request $request, ?\Illuminate\Database\Query\Builder $builder): void {
    $status = (string) $request->input('status');

    $builder?->where(['status_id' => $status]);
}
?>
--EXPECTF--
