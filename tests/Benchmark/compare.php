<?php

declare(strict_types=1);

/**
 * Compare two benchmark results and output a markdown table.
 *
 * Usage: php compare.php <base.json> <pr.json> [--time-threshold=15] [--memory-threshold=20]
 *
 * Exit codes:
 *   0 = within thresholds
 *   1 = regression detected or Psalm crashed
 *   2 = usage error / bad input
 */

$options = getopt('', ['time-threshold:', 'memory-threshold:']);
$timeThreshold = (float) ($options['time-threshold'] ?? 15.0);
$memoryThreshold = (float) ($options['memory-threshold'] ?? 20.0);

$positional = array_values(array_filter(
    array_slice($argv, 1),
    static fn(string $arg): bool => !str_starts_with($arg, '--'),
));

if (count($positional) < 2) {
    fwrite(STDERR, "Usage: php compare.php <base.json> <pr.json> [--time-threshold=15] [--memory-threshold=20]\n");
    exit(2);
}

[$baseFile, $prFile] = $positional;

// Validate input files exist and are readable
foreach (['base' => $baseFile, 'pr' => $prFile] as $label => $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "Error: {$label} result file not found: {$file}\n");
        exit(2);
    }
}

$decoded = [];
foreach (['base' => $baseFile, 'pr' => $prFile] as $label => $file) {
    $json = file_get_contents($file);
    if ($json === false) {
        fwrite(STDERR, "Error: failed to read {$label} result file: {$file}\n");
        exit(2);
    }

    try {
        $decoded[$label] = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        fwrite(STDERR, "Error: invalid {$label} JSON in {$file}: {$e->getMessage()}\n");
        exit(2);
    }
}

$base = $decoded['base'];
$pr = $decoded['pr'];

// Validate required keys
$required = ['wall_time_s', 'peak_memory_mb', 'psalm_exit_code'];
foreach (['base' => $base, 'pr' => $pr] as $label => $data) {
    foreach ($required as $key) {
        if (!array_key_exists($key, $data)) {
            fwrite(STDERR, "Error: missing key '{$key}' in {$label} JSON\n");
            exit(2);
        }
    }
}

// Check for Psalm crashes (signal kills: exit >= 128)
$baseExit = (int) $base['psalm_exit_code'];
$prExit = (int) $pr['psalm_exit_code'];
if ($baseExit >= 128 || $prExit >= 128) {
    echo "## Benchmark Results\n\n";
    echo "**Psalm crashed during benchmark — results are not comparable.**\n\n";
    echo sprintf("- Base exit code: %d%s\n", $baseExit, $baseExit >= 128 ? ' (signal ' . ($baseExit - 128) . ')' : '');
    echo sprintf("- PR exit code: %d%s\n", $prExit, $prExit >= 128 ? ' (signal ' . ($prExit - 128) . ')' : '');
    exit(1);
}

$timeDelta = $pr['wall_time_s'] - $base['wall_time_s'];
$timePct = $base['wall_time_s'] > 0 ? ($timeDelta / $base['wall_time_s']) * 100 : 0;

$memDelta = $pr['peak_memory_mb'] - $base['peak_memory_mb'];
$memPct = $base['peak_memory_mb'] > 0 ? ($memDelta / $base['peak_memory_mb']) * 100 : 0;

$timeSign = $timeDelta >= 0 ? '+' : '';
$memSign = $memDelta >= 0 ? '+' : '';

$timeRegression = $timePct > $timeThreshold;
$memRegression = $memPct > $memoryThreshold;
$failed = $timeRegression || $memRegression;

$status = $failed ? 'FAIL' : 'PASS';

// Exit code mismatch warning (non-crash, e.g. different error counts)
$exitWarning = '';
if ($baseExit !== $prExit) {
    $exitWarning = sprintf(
        "\n> **Note:** Psalm exit codes differ (base: %d, PR: %d). Results may not be fully comparable.\n",
        $baseExit,
        $prExit,
    );
}

echo "## Benchmark Results\n\n";
echo "| Metric | Base | PR | Delta |\n";
echo "|--------|------|-----|-------|\n";
echo sprintf(
    "| Wall time | %.1fs | %.1fs | %s%.1fs (%s%.1f%%) %s |\n",
    $base['wall_time_s'],
    $pr['wall_time_s'],
    $timeSign,
    $timeDelta,
    $timeSign,
    $timePct,
    $timeRegression ? '**REGRESSION**' : '',
);
echo sprintf(
    "| Peak memory | %.0fMB | %.0fMB | %s%.0fMB (%s%.1f%%) %s |\n",
    $base['peak_memory_mb'],
    $pr['peak_memory_mb'],
    $memSign,
    $memDelta,
    $memSign,
    $memPct,
    $memRegression ? '**REGRESSION**' : '',
);
echo $exitWarning;
echo "\n";
echo "**Status:** {$status} (thresholds: time <{$timeThreshold}%, memory <{$memoryThreshold}%)\n";

exit($failed ? 1 : 0);
