--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

class ArrayWhereConversation extends \Illuminate\Database\Eloquent\Model {}

/**
 * The static model form (via `@mixin Builder`) forwards to Eloquent\Builder::where.
 * The keyed-array form binds its values, so a tainted value must not be flagged. #734
 *
 * @psalm-suppress MixedAssignment
 */
function safeEloquentArrayWhere(\Illuminate\Http\Request $request): void {
    $fromId = $request->input('from_id');

    ArrayWhereConversation::where(['status_id' => 1, 'from_id' => $fromId]);
}
?>
--EXPECTF--
