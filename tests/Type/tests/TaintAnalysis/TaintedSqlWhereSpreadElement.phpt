--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * A spread element inside the array literal (`[...$rows, $tainted]`) contributes an unknown number of
 * elements under unknown keys, shifting every following position — the literal carries no reliable
 * positions at all, so recordBoundValuePositions bails on the whole literal and nothing is recorded.
 * The sink must still stand. Uses orWhereNot, not whereNot, per the batch-artifact guidance (see
 * TaintedSqlWhereWholeArrayInput.phpt) — this shape otherwise collides with the other whereNot-based
 * nested-condition tests when batch-analyzed in one psalm process. #1300
 *
 * @psalm-suppress TooFewArguments
 */
function unsafeSpreadElementOrWhereNot(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $rows = ['a'];

    $builder->orWhereNot([...$rows, (string) $request->input('c')]);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
