--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * The where-family stubs keep `@psalm-flow ($operator, $value) -> return` and deliberately do NOT
 * carry `@psalm-taint-specialize` — which would silently break propagation of non-SQL taint kinds
 * through the value positions (see WhereColumnTaintHandler / docs/contributing/taint-analysis.md).
 * Guard: a non-SQL (shell) taint on a where VALUE must still flow through the returned builder. If a
 * future change re-adds `@psalm-taint-specialize` to these stubs, this stops reporting.
 */
class WhereValueShellRunner {
    /** @psalm-taint-sink shell $cmd */
    public function run(mixed $cmd): void {}
}

/** @psalm-suppress TooFewArguments */
function shellFlowsThroughWhereValue(\Illuminate\Http\Request $request): void {
    $value = (string) $request->input('q');
    $builder = (new \Illuminate\Database\Query\Builder())->where('col', $value);

    (new WhereValueShellRunner())->run($builder);
}
?>
--EXPECTF--
%ATaintedShell on line %d: Detected tainted shell code
