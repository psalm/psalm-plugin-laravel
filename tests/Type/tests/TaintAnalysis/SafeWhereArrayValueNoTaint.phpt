--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * where(), orWhere(), whereNot(), orWhereNot() parameterize array-form values,
 * so tainted VALUES inside the condition map must not trigger TaintedSql.
 *
 * @psalm-suppress MixedAssignment
 */
function safeWhereArrayValue(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $id = $request->input('id');

    $builder->where(['status_id' => $id, 'active' => 1]);
    $builder->orWhere(['status_id' => $id]);
    $builder->whereNot(['status_id' => $id]);
    $builder->orWhereNot(['status_id' => $id]);
}
?>
--EXPECTF--
