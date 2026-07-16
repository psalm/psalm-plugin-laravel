--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * A spread argument (`where(...$args)`) unpacks a string-keyed array as NAMED arguments, so a tainted
 * value lands in the `$column` parameter directly — a real identifier injection. The Before-hook skips
 * unpacked first args (`$args[0]->unpack`), so nothing is recorded and the sink stands. Pins that
 * guard.
 *
 * @psalm-suppress TooFewArguments
 */
function unsafeUnpackedArrayWhere(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $args = ['column' => (string) $request->input('column')];

    $builder->where(...$args);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
