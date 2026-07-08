--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Prophylactic CANARY for the variable-form argument edge (`$conds = [...]; where($conds)`). Psalm
 * does not currently flag this shape even without the strip, so it stays green with the handler
 * disabled. It documents that the map-form FP must not appear for the variable form either, and would
 * catch a future upstream change that started flowing the sink through a local. It does not, on its
 * own, prove the variable-form recording fires (the inline literal form is the load-bearing pin). #734
 *
 * @psalm-suppress TooFewArguments, MixedAssignment
 */
function safeArrayVariableWhere(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $status = $request->input('status');

    $conds = ['status_id' => $status];
    $builder->where($conds);
}
?>
--EXPECTF--
