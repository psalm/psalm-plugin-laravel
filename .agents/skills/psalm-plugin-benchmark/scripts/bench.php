<?php

declare(strict_types=1);

/**
 * Automated performance benchmark for psalm-plugin-laravel.
 *
 * Runs Psalm on the IxDF test project with and without the plugin,
 * then prints a comparison table with overhead percentages.
 *
 * Usage:
 *   php bench.php [--runs=N] [--project=PATH] [--plugin=PATH]
 *
 * Defaults:
 *   --runs=3
 *   --project=/Users/alies/code/psalm/IxDF-as-example
 *   --plugin=/Users/alies/code/psalm/psalm-plugin-laravel
 */

$options = getopt('', ['runs:', 'project:', 'plugin:', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
    Usage: php bench.php [OPTIONS]

    Options:
      --runs=N        Runs per config (default: 3)
      --project=PATH  Test project path (default: /Users/alies/code/psalm/IxDF-as-example)
      --plugin=PATH   Plugin repo path (default: /Users/alies/code/psalm/psalm-plugin-laravel)
      --help          Show this help

    HELP;
    exit(0);
}

$runs       = (int) ($options['runs'] ?? 3);
$projectDir = $options['project'] ?? '/Users/alies/code/psalm/IxDF-as-example';
$pluginDir  = $options['plugin'] ?? '/Users/alies/code/psalm/psalm-plugin-laravel';

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------
if (!\is_file("{$projectDir}/psalm.xml")) {
    \fwrite(\STDERR, "ERROR: {$projectDir}/psalm.xml not found\n");
    exit(1);
}

if (!\is_dir($pluginDir)) {
    \fwrite(\STDERR, "ERROR: plugin dir {$pluginDir} not found\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Update Psalm to latest dev-master before benchmarking
// ---------------------------------------------------------------------------
\fwrite(\STDERR, "Updating Psalm to latest dev-master...\n");
$updateOutput = \shell_exec(\sprintf(
    'cd %s && composer update vimeo/psalm --no-interaction --quiet 2>&1',
    \escapeshellarg($projectDir),
));
if ($updateOutput !== null && $updateOutput !== '') {
    \fwrite(\STDERR, $updateOutput);
}
$psalmVersion = \trim(\shell_exec(\sprintf(
    'cd %s && php vendor/bin/psalm --version 2>&1',
    \escapeshellarg($projectDir),
)) ?: 'unknown');
\fprintf(\STDERR, "Psalm version: %s\n\n", $psalmVersion);

// ---------------------------------------------------------------------------
// Ensure plugin symlink points to the main plugin repo (not a stale worktree)
// ---------------------------------------------------------------------------
$symlinkPath = "{$projectDir}/vendor/psalm/plugin-laravel";
$currentTarget = \is_link($symlinkPath) ? \readlink($symlinkPath) : null;
$canonicalPlugin = \realpath($pluginDir);

if ($currentTarget !== null && \realpath($currentTarget) !== $canonicalPlugin) {
    \fprintf(\STDERR, "Fixing stale symlink: %s\n  was:  %s\n  now:  %s\n\n", $symlinkPath, $currentTarget, $pluginDir);
    \unlink($symlinkPath);
    \symlink($pluginDir, $symlinkPath);
} elseif ($currentTarget === null && \is_dir(\dirname($symlinkPath))) {
    \fprintf(\STDERR, "Creating plugin symlink: %s -> %s\n\n", $symlinkPath, $pluginDir);
    \symlink($pluginDir, $symlinkPath);
}


// Get branch name
$branch = \trim(\shell_exec("git -C " . \escapeshellarg($pluginDir) . " branch --show-current 2>/dev/null") ?: 'unknown');

// Count PHP files in project
$fileCount = (int) \trim(\shell_exec(
    "find " . \escapeshellarg($projectDir) . "/app "
    . \escapeshellarg($projectDir) . "/tests "
    . \escapeshellarg($projectDir) . "/config "
    . \escapeshellarg($projectDir) . "/database "
    . \escapeshellarg($projectDir) . "/routes "
    . "-name '*.php' 2>/dev/null | wc -l",
) ?: '0');

// ---------------------------------------------------------------------------
// Create no-plugin config
// ---------------------------------------------------------------------------
$psalmXml = \file_get_contents("{$projectDir}/psalm.xml");
if ($psalmXml === false) {
    \fwrite(\STDERR, "ERROR: cannot read psalm.xml\n");
    exit(1);
}

$nopluginXml = \preg_replace(
    '/\s*<pluginClass\s+class="Psalm\\\\LaravelPlugin\\\\Plugin">.*?<\/pluginClass>/s',
    '',
    $psalmXml,
);

$nopluginPath = "{$projectDir}/psalm-noplugin.xml";
\file_put_contents($nopluginPath, $nopluginXml);

// Verify removal
if (\str_contains($nopluginXml, 'LaravelPlugin')) {
    \fwrite(\STDERR, "ERROR: failed to remove LaravelPlugin from config\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Run benchmarks
// ---------------------------------------------------------------------------
function runPsalm(string $projectDir, string $configFile): ?array
{
    $escapedDir = \escapeshellarg($projectDir);
    $cmd = \sprintf(
        'cd %s && rm -rf .cache/psalm 2>/dev/null; cd %s && php -d memory_limit=-1 vendor/bin/psalm -c %s --no-suggestions --no-cache 2>&1',
        $escapedDir,
        $escapedDir,
        \escapeshellarg($configFile),
    );

    $output = \shell_exec($cmd);

    if ($output === null) {
        return null;
    }

    // Parse: "Checks took XX.XX seconds and used X,XXX.XXX MB of memory"
    if (\preg_match('/Checks took ([\d.]+) seconds and used ([\d,.]+)MB/', $output, $m)) {
        return [
            'time' => (float) $m[1],
            'memory' => (float) \str_replace(',', '', $m[2]),
        ];
    }

    return null;
}

\fprintf(\STDERR, "Plugin branch: %s\n", $branch);
\fprintf(\STDERR, "Test project: %s (~%d PHP files)\n", $projectDir, $fileCount);
\fprintf(\STDERR, "Runs per config: %d\n\n", $runs);

$withResults = [];
$withoutResults = [];

// Run without-plugin first (OS filesystem cache is cold — fairer baseline)
for ($i = 1; $i <= $runs; $i++) {
    \fprintf(\STDERR, "Without plugin: run %d/%d... ", $i, $runs);
    $result = runPsalm($projectDir, 'psalm-noplugin.xml');
    if ($result === null) {
        \fwrite(\STDERR, "FAILED\n");
        continue;
    }
    $withoutResults[] = $result;
    \fprintf(\STDERR, "%.2fs / %.0f MB\n", $result['time'], $result['memory']);
}

for ($i = 1; $i <= $runs; $i++) {
    \fprintf(\STDERR, "With plugin:    run %d/%d... ", $i, $runs);
    $result = runPsalm($projectDir, 'psalm.xml');
    if ($result === null) {
        \fwrite(\STDERR, "FAILED\n");
        continue;
    }
    $withResults[] = $result;
    \fprintf(\STDERR, "%.2fs / %.0f MB\n", $result['time'], $result['memory']);
}

// ---------------------------------------------------------------------------
// Clean up
// ---------------------------------------------------------------------------
@\unlink($nopluginPath);

// ---------------------------------------------------------------------------
// Calculate & output results
// ---------------------------------------------------------------------------
if ($withResults === [] || $withoutResults === []) {
    \fwrite(\STDERR, "\nERROR: not enough successful runs to compare\n");
    exit(1);
}

$avgWith    = \array_sum(\array_column($withResults, 'time')) / \count($withResults);
$avgWithout = \array_sum(\array_column($withoutResults, 'time')) / \count($withoutResults);
$avgMemWith    = \array_sum(\array_column($withResults, 'memory')) / \count($withResults);
$avgMemWithout = \array_sum(\array_column($withoutResults, 'memory')) / \count($withoutResults);

$timeOverhead = $avgWith - $avgWithout;
$timeOverheadPct = ($avgWithout > 0) ? ($timeOverhead / $avgWithout) * 100 : 0;
$memOverhead = $avgMemWith - $avgMemWithout;
$memOverheadPct = ($avgMemWithout > 0) ? ($memOverhead / $avgMemWithout) * 100 : 0;

$verdict = (\abs($timeOverheadPct) <= 15 && \abs($memOverheadPct) <= 15) ? 'PASS' : 'FAIL';

// Output markdown table
echo "## Benchmark Results\n\n";
echo "**Branch:** `{$branch}`\n";
echo "**Runs per config:** {$runs}\n";
echo "**Test project:** IxDF (~{$fileCount} PHP files)\n\n";

echo "| Run | With Plugin (s) | Without Plugin (s) | With Plugin (MB) | Without Plugin (MB) |\n";
echo "|-----|----------------:|-------------------:|-----------------:|--------------------:|\n";

$maxRuns = \max(\count($withResults), \count($withoutResults));
for ($i = 0; $i < $maxRuns; $i++) {
    $wt = isset($withResults[$i]) ? \sprintf('%.2f', $withResults[$i]['time']) : 'n/a';
    $wot = isset($withoutResults[$i]) ? \sprintf('%.2f', $withoutResults[$i]['time']) : 'n/a';
    $wm = isset($withResults[$i]) ? \sprintf('%.0f', $withResults[$i]['memory']) : 'n/a';
    $wom = isset($withoutResults[$i]) ? \sprintf('%.0f', $withoutResults[$i]['memory']) : 'n/a';
    echo "| " . ($i + 1) . " | {$wt} | {$wot} | {$wm} | {$wom} |\n";
}

echo \sprintf(
    "| **Avg** | **%.2f** | **%.2f** | **%.0f** | **%.0f** |\n",
    $avgWith, $avgWithout, $avgMemWith, $avgMemWithout,
);

echo "\n| Metric | Overhead |\n";
echo "|--------|----------|\n";
echo \sprintf("| Time   | %+.2fs (%+.1f%%) |\n", $timeOverhead, $timeOverheadPct);
echo \sprintf("| Memory | %+.0f MB (%+.1f%%) |\n", $memOverhead, $memOverheadPct);

echo "\n**Verdict:** {$verdict}";
if ($verdict === 'FAIL') {
    echo ' (>15% threshold exceeded)';
}
echo "\n";

// ---------------------------------------------------------------------------
// Store historical overhead metrics
// ---------------------------------------------------------------------------
$historyFile = __DIR__ . '/../history.json';
$history = [];
if (\is_file($historyFile)) {
    $decoded = \json_decode(\file_get_contents($historyFile), true);
    if (\is_array($decoded)) {
        $history = $decoded;
    }
}

$gitRef = \trim(\shell_exec("git -C " . \escapeshellarg($pluginDir) . " rev-parse --short HEAD 2>/dev/null") ?: 'unknown');

$history[] = [
    'date' => \date('Y-m-d H:i'),
    'branch' => $branch,
    'commit' => $gitRef,
    'psalm' => $psalmVersion,
    'runs' => $runs,
    'time_overhead_s' => \round($timeOverhead, 2),
    'time_overhead_pct' => \round($timeOverheadPct, 1),
    'mem_overhead_mb' => \round($memOverhead, 0),
    'mem_overhead_pct' => \round($memOverheadPct, 1),
    'verdict' => $verdict,
];

\file_put_contents($historyFile, \json_encode($history, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");

// Print history table (last 10 entries)
$recent = \array_slice($history, -10);
echo "\n## History (last " . \count($recent) . " runs)\n\n";
echo "| Date | Branch | Commit | Time Overhead | Memory Overhead | Verdict |\n";
echo "|------|--------|--------|--------------|----------------|--------|\n";
foreach ($recent as $entry) {
    echo \sprintf(
        "| %s | %s | %s | %+.2fs (%+.1f%%) | %+.0f MB (%+.1f%%) | %s |\n",
        $entry['date'],
        $entry['branch'],
        $entry['commit'],
        $entry['time_overhead_s'],
        $entry['time_overhead_pct'],
        $entry['mem_overhead_mb'],
        $entry['mem_overhead_pct'],
        $entry['verdict'],
    );
}

exit($verdict === 'PASS' ? 0 : 1);
