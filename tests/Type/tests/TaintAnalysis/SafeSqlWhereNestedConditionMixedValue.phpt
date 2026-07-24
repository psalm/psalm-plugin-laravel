--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

class StaticNestedConditionMixedValueArticle extends \Illuminate\Database\Eloquent\Model {}

/**
 * `Request::__get` (#1301) sources magic-property reads at type `mixed` — no cast narrows it to
 * `Scalar|null`. The inner nested-condition value ordinal used to reuse the outer numeric-key
 * element's scalar-only gate, so this `mixed` value failed it and the sink survived. It always
 * strips now: unlike the outer position, this ordinal has no further runtime re-dispatch waiting
 * for it — `addBinding()` binds it unconditionally, the sole exception (`DB::raw()`) being flagged
 * at its own construction sink instead. #1300
 */
function safeStaticNestedConditionMixedValueThreeElementForm(\Illuminate\Http\Request $request): void {
    StaticNestedConditionMixedValueArticle::where([['name', '=', $request->keyword]]);
}

/**
 * Same mixed-value gap, 2-element form (ordinal 1 is `$value` here, not `$operator`). #1300
 */
function safeStaticNestedConditionMixedValueTwoElementForm(\Illuminate\Http\Request $request): void {
    StaticNestedConditionMixedValueArticle::where([['id', $request->keyword]]);
}

/**
 * Instance-receiver variant of the same gap, on the `Query\Builder` sink directly. #1300
 *
 * @psalm-suppress TooFewArguments
 */
function safeInstanceNestedConditionMixedValue(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();

    $builder->where([['id', '<>', $request->keyword]]);
}
?>
--EXPECTF--
