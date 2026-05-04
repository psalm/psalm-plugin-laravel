--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

class EloquentWhereArrayPost extends \Illuminate\Database\Eloquent\Model {}

/**
 * Eloquent where(), orWhere(), whereNot(), orWhereNot() parameterize array-form
 * values, so tainted VALUES inside the condition map must not trigger TaintedSql.
 *
 * @psalm-suppress MixedAssignment
 */
function safeEloquentWhereArrayValue(\Illuminate\Http\Request $request): void {
    $id = $request->input('id');

    EloquentWhereArrayPost::where(['status_id' => $id, 'active' => 1]);
    EloquentWhereArrayPost::orWhere(['status_id' => $id]);
    EloquentWhereArrayPost::whereNot(['status_id' => $id]);
    EloquentWhereArrayPost::orWhereNot(['status_id' => $id]);
}
?>
--EXPECTF--
