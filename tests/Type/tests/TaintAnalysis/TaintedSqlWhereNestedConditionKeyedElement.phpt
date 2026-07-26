--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/**
 * An explicit key inside a nested condition breaks the source-order-is-parameter-position mapping,
 * because a key collision replaces an element in place and leaves the literal one element shorter:
 * `['name', 0.5 => $column]` is really `[$column]` (0.5 casts to the int key 0), so array_values()
 * puts the source-ordinal-1 element at ordinal 0 — the raw column. recordNestedConditionPositions
 * therefore records nothing once any inner element carries a key, and the sink stands.
 *
 * Found in review of #1300; the float-key form emits no other issue at all, so nothing else would
 * have caught it.
 *
 * Runs in the separate `--threads=1` ARGS group: the default taint group already holds five
 * keep-taint tests competing for the same where/whereNot/orWhereNot sink nodes, and the upstream
 * batch artifact (see TaintedSqlWhereWholeArrayInput.phpt) swallows one of the two findings below
 * once they join it. Both flag reliably in a process that is not saturated. #1300
 *
 * @psalm-suppress TooFewArguments
 */
function unsafeNestedConditionCollidingFloatKey(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $column = (string) $request->input('column');

    $builder->whereNot([['name', 0.5 => $column]]);
}

/**
 * The string-literal variant of the same collision. Psalm does emit DuplicateArrayKey here, so this
 * shape is only reachable in a project that suppresses it — pinned anyway, since the bail covers
 * both and a narrower fix would not. #1300
 *
 * @psalm-suppress TooFewArguments, DuplicateArrayKey
 */
function unsafeNestedConditionDuplicateStringKey(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $column = (string) $request->input('column');

    $builder->orWhereNot([['k' => 'name', 'k' => $column]]);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
%ATaintedSql on line %d: Detected tainted SQL
