--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// Psalm 7.0.0-beta21 drops one of two taint flows that reach the same sink method from two
// different files in a co-analyzed batch, so this fixture's finding disappears when the suite
// runs as a whole while it is still reported on its own.
// @todo-by 2026-10-01 drop this gate once vimeo/psalm#11959 ships a fix; if the issue is still
// open then, re-check whether the batch still hides the finding and move the date.
// @see https://github.com/vimeo/psalm/issues/11959
\Tests\Psalm\LaravelPlugin\Type\PsalmVersion::skipOnRange('7.0.0-beta21', '7.0.0-beta22');
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * The `Query\Builder::where()` stub (the one exercised here) keeps `@psalm-flow ($operator, $value) ->
 * return` and deliberately does NOT carry `@psalm-taint-specialize`, which would silently break
 * propagation of non-SQL taint kinds through the value positions (see WhereColumnTaintHandler /
 * docs/contributing/taint-analysis.md). Guard: a non-SQL (shell) taint on a where VALUE must still flow
 * through the returned builder. If a future change adds `@psalm-taint-specialize` to this stub, this
 * stops reporting. (Some Eloquent stubs such as firstWhere DO carry `@psalm-taint-specialize`; this
 * guard is scoped to the Query\Builder where stub under test.)
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
TaintedShell on line %d: Detected tainted shell code
