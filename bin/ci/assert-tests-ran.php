<?php

declare(strict_types=1);

/**
 * Assert that a PHPUnit JUnit log actually exercised a subset of tests,
 * rather than every one of them being silently SKIPIF'd.
 *
 * Filtering with `--filter=<needle>` does not avoid this problem here: this
 * repo's phpt runner (`tests/Type/PsalmTest::setUpBeforeClass()`) batches
 * every discovered phpt through Psalm regardless of PHPUnit's `--filter`, so
 * a filtered re-run pays the same cost as the full suite for no benefit.
 * Read the JUnit log the full run already produced instead of running a
 * second time.
 *
 * Usage: php bin/ci/assert-tests-ran.php <junit-file> <name-needle>
 * Exit codes: 0 = at least one matching test ran and not all were skipped,
 * 1 = zero matching tests found or every matching test was skipped, 2 =
 * usage error.
 */

if (\count($argv) !== 3) {
    \fwrite(\STDERR, "Usage: php bin/ci/assert-tests-ran.php <junit-file> <name-needle>\n");
    exit(2);
}

[, $junitFile, $needle] = $argv;

$xml = \simplexml_load_file($junitFile);
if ($xml === false) {
    \fwrite(\STDERR, "Could not parse JUnit file: {$junitFile}\n");
    exit(2);
}

/** @var list<\SimpleXMLElement> $testcases */
$testcases = $xml->xpath('//testcase');
$matching = \array_values(\array_filter(
    $testcases,
    static fn(\SimpleXMLElement $testcase): bool => \str_contains((string) $testcase['name'], $needle),
));

$total = \count($matching);
$skipped = \count(\array_filter($matching, static fn(\SimpleXMLElement $testcase): bool => property_exists($testcase, 'skipped') && $testcase->skipped !== null));

echo "{$needle}: {$total} tests, {$skipped} skipped\n";

if ($total === 0) {
    echo "::error::No tests matching \"{$needle}\" were found in {$junitFile}. The test layout or naming may have changed.\n";
    exit(1);
}

if ($skipped >= $total) {
    echo "::error::Every test matching \"{$needle}\" was skipped ({$skipped}/{$total}). This run is NOT exercising that coverage.\n";
    exit(1);
}

if ($skipped > 0) {
    echo "::warning::{$skipped}/{$total} tests matching \"{$needle}\" were skipped. Check their SKIPIF conditions.\n";
}

exit(0);
