--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * `whereColumn()`/`orWhereColumn()` bind nothing: the grammar wraps `$first` and `$second` as raw
 * identifiers, and a `$operator` that fails Laravel's whitelist is demoted into `$second` by
 * `invalidOperator()` rather than discarded. So a tainted value flags whichever positional slot it
 * lands in — `$first`, `$operator`, or `$second` — and `orWhereColumn` shares the same three sinks.
 * #1303
 *
 * @psalm-suppress TooFewArguments
 */
function unsafeWhereColumnFirst(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $tainted = (string) $request->input('col');

    $builder->whereColumn($tainted, '=', 'x');
}

/**
 * @psalm-suppress TooFewArguments
 */
function unsafeWhereColumnSecond(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $tainted = (string) $request->input('col');

    $builder->whereColumn('a', '=', $tainted);
}

/**
 * @psalm-suppress TooFewArguments
 */
function unsafeOrWhereColumnFirst(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $tainted = (string) $request->input('col');

    $builder->orWhereColumn($tainted, '=', 'x');
}

/**
 * @psalm-suppress TooFewArguments
 */
function unsafeOrWhereColumnOperator(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $tainted = (string) $request->input('op');

    $builder->orWhereColumn('a', $tainted, 'b');
}

/**
 * @psalm-suppress TooFewArguments
 */
function unsafeOrWhereColumnSecond(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $tainted = (string) $request->input('col');

    $builder->orWhereColumn('a', '=', $tainted);
}

/**
 * The operator itself is a sink: an operator that does not match Laravel's whitelist is swapped into
 * `$second` by `invalidOperator()` (Query/Builder.php:1184) and compiled raw either way.
 *
 * @psalm-suppress TooFewArguments
 */
function unsafeWhereColumnOperator(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $tainted = (string) $request->input('op');

    $builder->whereColumn('a', $tainted, 'b');
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
%ATaintedSql on line %d: Detected tainted SQL
%ATaintedSql on line %d: Detected tainted SQL
%ATaintedSql on line %d: Detected tainted SQL
%ATaintedSql on line %d: Detected tainted SQL
%ATaintedSql on line %d: Detected tainted SQL
