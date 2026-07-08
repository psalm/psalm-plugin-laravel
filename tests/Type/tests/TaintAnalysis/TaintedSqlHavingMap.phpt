--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * having() is deliberately NOT in WHERE_MAP_METHODS: Laravel's having() has no `is_array($column)`
 * branch, so an array column is never value-bound (it compiles raw into the havings clause). A tainted
 * value in a having map must therefore still flag. Pins that exclusion — a future "having looks like
 * where" addition to the allowlist would silently create a false negative here.
 *
 * (having()'s `$column` stub type is `Expression|Closure|string`, so the array literal also draws an
 * InvalidArgument; that is a separate stub concern, suppressed so the taint assertion stands alone.)
 *
 * @psalm-suppress TooFewArguments, InvalidArgument
 */
function unsafeHavingMap(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();

    $builder->having(['total' => (string) $request->input('t')], null);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
