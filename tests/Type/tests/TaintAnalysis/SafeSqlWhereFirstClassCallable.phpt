--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * A first-class-callable `where(...)` has no arguments; `getArgs()` throws on it, so the Before-hook
 * must skip via `isFirstClassCallable()`. Pins that crash guard (expect analysis to complete with no
 * output).
 *
 * @psalm-suppress TooFewArguments
 */
function safeFirstClassCallableWhere(): void {
    $builder = new \Illuminate\Database\Query\Builder();

    $fn = $builder->where(...);
    $fn('status', 'active');
}
?>
--EXPECTF--
