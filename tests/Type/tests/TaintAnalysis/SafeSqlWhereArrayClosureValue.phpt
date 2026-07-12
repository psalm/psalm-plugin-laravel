--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

class ClosureReceiverModel extends \Illuminate\Database\Eloquent\Model {}

/**
 * Regression guard for the flush lifecycle. A closure/arrow-fn completes a FunctionLikeAnalyzer
 * BETWEEN the Before-hook recording the map argument node and its removeTaints dispatch; a
 * per-function-like flush would wipe the record there and the #734 FP would return. The flush must be
 * per-FILE. Two triggers, one file:
 *
 *  1. arrow-fn in the receiver chain (analyzed before the outer where's args);
 *  2. closure invoked among the map values (analyzed before processTaintedness).
 */
function safeClosureInReceiverChain(\Illuminate\Http\Request $request): void {
    $status = (string) $request->input('status');

    ClosureReceiverModel::whereHas('roles', static fn (): bool => true)
        ->where(['status_id' => $status]);
}

/**
 * @psalm-suppress TooFewArguments
 */
function safeClosureAmongMapValues(\Illuminate\Http\Request $request): void {
    $status = (string) $request->input('status');

    $builder = new \Illuminate\Database\Query\Builder();
    $builder->where(['status_id' => $status, 'flag' => (static fn (): int => 1)()]);
}
?>
--EXPECTF--
