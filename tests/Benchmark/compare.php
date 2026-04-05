<?php

declare(strict_types=1);

/**
 * Build a PR benchmark report from hyperfine JSON + memory capture files.
 *
 * Usage: php compare.php <hyperfine.json> <base-mem.txt> <pr-mem.txt> [base-issues.txt] [pr-issues.txt] [base-stats.txt] [pr-stats.txt] [--time-threshold=15] [--memory-threshold=20]
 *
 * Exit codes:
 *   0 = within thresholds
 *   1 = regression detected, or results are not comparable (Psalm crashed)
 *   2 = usage error / bad input
 */

$options = getopt('', ['time-threshold:', 'memory-threshold:']);
$timeThreshold = (float) ($options['time-threshold'] ?? 15.0);
$memoryThreshold = (float) ($options['memory-threshold'] ?? 20.0);

$positional = array_values(array_filter(
    array_slice($argv, 1),
    static fn(string $arg): bool => !str_starts_with($arg, '--'),
));

if (count($positional) < 3) {
    fwrite(STDERR, "Usage: php compare.php <hyperfine.json> <base-mem.txt> <pr-mem.txt> [base-issues.txt] [pr-issues.txt] [base-stats.txt] [pr-stats.txt] [--time-threshold=15] [--memory-threshold=20]\n");
    exit(2);
}

[$hyperfineFile, $baseMemFile, $prMemFile] = $positional;
$baseIssuesFile = $positional[3] ?? null;
$prIssuesFile = $positional[4] ?? null;
$baseStatsFile = $positional[5] ?? null;
$prStatsFile = $positional[6] ?? null;

$fail = static function (string $message): never {
    echo "## Benchmark Results\n\n**Error:** {$message}\n";
    fwrite(STDERR, "Error: {$message}\n");
    exit(2);
};

// Read hyperfine JSON
$hyperfineJson = file_get_contents($hyperfineFile);
if ($hyperfineJson === false) {
    $fail("unable to read hyperfine results: {$hyperfineFile}");
}

try {
    $hyperfine = json_decode($hyperfineJson, true, 512, JSON_THROW_ON_ERROR);
} catch (\JsonException $jsonException) {
    $fail("invalid hyperfine JSON: {$jsonException->getMessage()}");
}

$results = $hyperfine['results'] ?? [];
if (count($results) < 2) {
    $fail("expected 2 commands in hyperfine results, got " . count($results));
}

$baseTiming = $results[0];
$prTiming = $results[1];

// Validate exit codes — Psalm 0 (no issues) and 2 (issues found) both mean analysis completed.
// Exit 1 = config/runtime error, >=128 = signal (crash). hyperfine records per-run exit codes.
foreach (['base' => $baseTiming, 'pr' => $prTiming] as $label => $timing) {
    $exitCodes = $timing['exit_codes'] ?? [];
    $failures = array_filter($exitCodes, static fn(int $code): bool => $code === 1 || $code >= 128);
    if ($failures !== []) {
        echo "## Benchmark Results\n\n";
        echo sprintf("**%s Psalm did not complete analysis — results are not comparable.**\n\n", ucfirst($label));
        echo sprintf("- Exit codes across runs: %s\n", implode(', ', $exitCodes));
        exit(1);
    }
}

// Read peak memory from capture files (max of all runs per command).
// These files are written by run-psalm.sh in the same CI job — one float per line.
$readMaxMemory = static function (string $file) use ($fail): float {
    $rawLines = file($file);
    if ($rawLines === false) {
        $fail("failed to read memory file: {$file}");
    }

    $lines = array_values(array_filter(array_map('trim', $rawLines), static fn(string $l): bool => $l !== ''));
    if ($lines === []) {
        $fail("memory file is empty: {$file}");
    }

    return max(array_map('floatval', $lines));
};

$baseMem = $readMaxMemory($baseMemFile);
$prMem = $readMaxMemory($prMemFile);

// Read optional capture files (issue counts, type coverage) — last run's value
$readLastLine = static function (?string $file): ?string {
    if ($file === null || !is_file($file)) {
        return null;
    }

    $lines = array_filter(array_map('trim', file($file) ?: []), static fn(string $l): bool => $l !== '');

    return $lines === [] ? null : end($lines);
};

$baseIssues = ($v = $readLastLine($baseIssuesFile)) !== null ? (int) $v : null;
$prIssues = ($v = $readLastLine($prIssuesFile)) !== null ? (int) $v : null;
$baseCoverage = ($v = $readLastLine($baseStatsFile)) !== null ? (float) $v : null;
$prCoverage = ($v = $readLastLine($prStatsFile)) !== null ? (float) $v : null;

// Validate metrics are positive
foreach (['base' => [$baseTiming['mean'], $baseMem], 'pr' => [$prTiming['mean'], $prMem]] as $label => [$time, $mem]) {
    if ($time <= 0 || $mem <= 0) {
        $fail("{$label} metrics are invalid (time: {$time}s, memory: {$mem}MB)");
    }
}

// Compute deltas
$timeDelta = $prTiming['mean'] - $baseTiming['mean'];
$timePct = ($timeDelta / $baseTiming['mean']) * 100;

$memDelta = $prMem - $baseMem;
$memPct = ($memDelta / $baseMem) * 100;

$timeSign = $timeDelta >= 0 ? '+' : '';
$memSign = $memDelta >= 0 ? '+' : '';

$timeRegression = $timePct > $timeThreshold;
$memRegression = $memPct > $memoryThreshold;
$failed = $timeRegression || $memRegression;

// Build report
echo "## Benchmark Results\n\n";

// Timing table (from hyperfine data, with stddev)
echo "| Metric | Base | PR | Delta |\n";
echo "|--------|------|-----|-------|\n";
echo sprintf(
    "| Wall time | %.1fs ± %.1fs | %.1fs ± %.1fs | %s%.1fs (%s%.1f%%) %s |\n",
    $baseTiming['mean'],
    $baseTiming['stddev'],
    $prTiming['mean'],
    $prTiming['stddev'],
    $timeSign,
    $timeDelta,
    $timeSign,
    $timePct,
    $timeRegression ? '**REGRESSION**' : '',
);
echo sprintf(
    "| Peak memory | %.0fMB | %.0fMB | %s%.0fMB (%s%.1f%%) %s |\n",
    $baseMem,
    $prMem,
    $memSign,
    $memDelta,
    $memSign,
    $memPct,
    $memRegression ? '**REGRESSION**' : '',
);
if ($baseIssues !== null && $prIssues !== null) {
    $issueDelta = $prIssues - $baseIssues;
    $issueSign = $issueDelta >= 0 ? '+' : '';
    echo sprintf(
        "| Issues found | %s | %s | %s%s |\n",
        number_format($baseIssues),
        number_format($prIssues),
        $issueSign,
        number_format($issueDelta),
    );
}

if ($baseCoverage !== null && $prCoverage !== null) {
    $coverageDelta = $prCoverage - $baseCoverage;
    $coverageSign = $coverageDelta >= 0 ? '+' : '';
    echo sprintf(
        "| Type coverage | %.2f%% | %.2f%% | %s%.2f%% |\n",
        $baseCoverage,
        $prCoverage,
        $coverageSign,
        $coverageDelta,
    );
}

echo sprintf(
    "\n*%d run(s), 1 warmup. Measured by [hyperfine](https://github.com/sharkdp/hyperfine).*\n",
    count($baseTiming['times']),
);

$message = $failed
 ? "**Status:** 🔴 FAIL (thresholds: time <{$timeThreshold}%, memory <{$memoryThreshold}%)"
 : "**Status:** 🟢 PASS";
echo "\n{$message}\n";

exit($failed ? 1 : 0);
