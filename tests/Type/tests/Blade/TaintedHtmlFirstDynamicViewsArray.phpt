--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Locks in the `Factory::first()` dynamic-array fallback. When the views
 * array contains a non-literal item, the handler cannot enumerate which
 * candidate template will render, so it installs an html sink on every
 * entry of the data array. The tainted `$_GET['x']` reaching the 'bio'
 * key must then surface as TaintedHtml.
 *
 * Sibling cases that require literal view-name + on-disk template fixtures
 * (e.g. "literal unsafe-keys template fires per-key sink") would need a
 * custom Testbench view path with fixture blade files. PR-3 documented
 * that the PHPT layer cannot provide that today; tracked as future work
 * alongside the Application --taint-analysis run.
 */
function renderFirstWithDynamicItem(\Illuminate\View\Factory $factory, string $candidate): void {
    /** @var string $tainted */
    $tainted = $_GET['x'];
    $factory->first(['home', $candidate], ['bio' => $tainted]);
}
?>
--EXPECTF--
%ATaintedHtml%a
