--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Negative regression: `Factory::first()` with an all-literal views array
 * where NONE of the candidate views exist in the {@see BladeSafetyMap}
 * (the default Testbench app has no fixture blade templates). The
 * dispatcher follows PR-3's "view not in map → no sink" policy and
 * installs no html sink for the call site, so no TaintedHtml is raised
 * even though `$_GET['x']` flows to the data array.
 *
 * A regression that switched the policy to "unknown view → whole-data
 * sink" would re-introduce the package-view false positives PR #684
 * fixed. MissingViewHandler is the appropriate surface for these typos.
 */
function renderFirstAllLiterals(\Illuminate\View\Factory $factory, string $tainted): void {
    $factory->first(['home', 'fallback'], ['bio' => $tainted]);
}
?>
--EXPECTF--

