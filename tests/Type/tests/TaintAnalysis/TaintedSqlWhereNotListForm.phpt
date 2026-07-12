--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * The int-key branch of isBoundValueMap. A LIST literal `whereNot([$tainted])` (integer key 0) is a
 * nested-condition form whose element[0] is a raw column, so it must still flag — the only coverage of
 * both the integer-key rejection AND of whereNot.
 *
 * @psalm-suppress TooFewArguments
 */
function unsafeListFormWhereNot(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $column = (string) $request->input('column');

    $builder->whereNot([$column]);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
