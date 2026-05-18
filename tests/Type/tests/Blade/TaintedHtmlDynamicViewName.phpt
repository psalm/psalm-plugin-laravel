--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Locks in the dynamic-view-name fallback: when the view name is not a literal,
 * the BladeAwareViewTaintHandler cannot resolve which template will render, so
 * it installs an html sink on every entry of the data array. The tainted
 * `$_GET['x']` reaching the 'bio' key must then surface as TaintedHtml.
 *
 * Sibling cases (Safe / UnsafeKeys / Unknown) need fixture blade templates on
 * the booted Testbench view path; they live in tests/Application/ rather than
 * here. See docs/issues/581-blade-taint-exploration.md for the PR-3 split.
 */
function renderDynamic(string $viewName): void {
    /** @var string $tainted */
    $tainted = $_GET['x'];
    view($viewName, ['bio' => $tainted]);
}
?>
--EXPECTF--
%ATaintedHtml%a
