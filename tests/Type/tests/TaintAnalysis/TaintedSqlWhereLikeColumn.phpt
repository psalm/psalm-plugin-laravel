--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * The column position of the whereLike family is a real identifier sink (interpolated raw, never
 * bound) that was silently unstubbed before #1300 — whereLike() and orWhereNotLike() both pin it here
 * so a tainted column must flag on each.
 *
 * @psalm-suppress TooFewArguments
 */
function unsafeWhereLikeColumn(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $column = (string) $request->input('column');

    $builder->whereLike($column, 'x');
}

/**
 * @psalm-suppress TooFewArguments
 */
function unsafeOrWhereNotLikeColumn(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $column = (string) $request->input('column');

    $builder->orWhereNotLike($column, 'x');
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
%ATaintedSql on line %d: Detected tainted SQL
