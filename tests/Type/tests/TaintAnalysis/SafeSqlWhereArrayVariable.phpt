--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * LOAD-BEARING, not a canary, for the variable-form argument edge (`$conds = [...]; where($conds)`).
 * The sink DOES flow through a local variable argument (verified empirically: the same shape with a
 * `whereAll()` receiver — a sink the element-wise/whole-argument strip never touches — still fires),
 * so this test stays green only because the WHOLE-ARGUMENT strip ({@see
 * \Psalm\LaravelPlugin\Handlers\Eloquent\WhereColumnTaintHandler::isBoundValueMap}) fires on the
 * type-level shape. #734, #1336
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
