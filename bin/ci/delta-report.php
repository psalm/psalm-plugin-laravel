<?php

declare(strict_types=1);

/**
 * Build a base-vs-head issue-delta report (markdown) from per-app Psalm JSON.
 *
 * Reads issues.json + perf.json files produced by delta-app.sh out of an
 * OUTPUT_DIR, matches base/head pairs per app, and prints a delta-only report:
 *
 *   * per-app deltas (issues added/removed, net, type coverage %, wall seconds)
 *   * top issue-type movements (added/removed by type) across all apps
 *   * newly introduced / removed issue types
 *
 * PHP port of the local /psalm-delta skill's compare.py — same file layout,
 * same identity tuple, same report shape — so CI and the local harness produce
 * byte-comparable output. Mirrors tests/Benchmark/compare.php conventions
 * (getopt, JSON_THROW_ON_ERROR, markdown to stdout). No Python on CI.
 *
 * File layout (written by delta-app.sh, identical to bench.sh):
 *   <output_dir>/<app>/<app>-<label>-<date-marker>--issues.json
 *   <output_dir>/<app>/<app>-<label>-<date-marker>--perf.json
 *
 * Issue identity uses (file_path, line_from, line_to, type, message) so churn
 * is visible: a PR fixing 50 issues and introducing 50 new ones reports
 * +50/-50 instead of ΔNet=0.
 *
 * Usage:
 *   php delta-report.php <output_dir> <base_label> <head_label> \
 *       --apps=monica,pixelfed,coolify \
 *       [--base-ref=] [--head-ref=] [--base-sha=] [--head-sha=] \
 *       [--date-marker=cache] [--top=30]
 *
 * Exit codes: 0 = report produced, 2 = usage error.
 */

// Psalm issues.json carry full code snippets, so a large app's report decodes
// to >100 MB — well past the default 128 MB CLI limit. This is a throwaway
// reporting tool (one process, exits immediately); a generous floor is safe.
// Honour a higher pre-set limit / unlimited (-1); only raise a too-low one.
$currentLimit = (int) ini_get('memory_limit');
if ($currentLimit !== -1 && $currentLimit < 1024 * 1024 * 1024) {
    ini_set('memory_limit', '1G');
}

// Manual arg parse: PHP's getopt() stops at the first non-option argument, so
// `<positional> --opt=v` would silently drop the options. Positionals and
// `--key=value` flags may appear in any order here.
$options = [];
$positional = [];
/** @var string $arg */
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, '');
        $options[$key] = $value;
    } else {
        $positional[] = $arg;
    }
}

$fail = static function (string $message): never {
    fwrite(STDERR, "Error: {$message}\n");
    fwrite(STDERR, "Usage: php delta-report.php <output_dir> <base_label> <head_label> --apps=a,b,c [--base-ref=] [--head-ref=] [--base-sha=] [--head-sha=] [--date-marker=cache]\n");
    exit(2);
};

if (count($positional) < 3) {
    $fail('expected <output_dir> <base_label> <head_label>');
}

[$outputDir, $baseLabel, $headLabel] = $positional;
$outputDir = rtrim($outputDir, '/');

$appsRaw = $options['apps'] ?? '';
$apps = array_values(array_filter(array_map('trim', explode(',', $appsRaw)), static fn(string $a): bool => $a !== ''));
if ($apps === []) {
    $fail('--apps is required (comma-separated app names, in display order)');
}

$baseRef = $options['base-ref'] ?? '';
$headRef = $options['head-ref'] ?? '';
$baseSha = $options['base-sha'] ?? '';
$headSha = $options['head-sha'] ?? '';
$dateMarker = $options['date-marker'] ?? 'cache';

/**
 * Newest file matching <app>-<label>-<date-marker>--<suffix>, or null.
 *
 * Mirrors compare.py's _latest: the date component anchors the match so label
 * "v4.10.0" does not also match "v4.10.0-pr905--...". With a literal marker
 * (e.g. "cache") there is a single deterministic name; the glob still sorts so
 * a yyyy-mm-dd marker would pick the most recent.
 */
$latest = static function (string $outputDir, string $app, string $label, string $suffix, string $dateMarker): ?string {
    if ($dateMarker === 'yyyy-mm-dd') {
        $dateGlob = '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]';
    } else {
        $dateGlob = $dateMarker;
    }
    $pattern = "{$outputDir}/{$app}/{$app}-{$label}-{$dateGlob}--{$suffix}";
    $matches = glob($pattern);
    if ($matches === false || $matches === []) {
        return null;
    }
    sort($matches);

    return $matches[array_key_last($matches)];
};

/**
 * @return array<array-key, mixed>|null  decoded JSON array, or null on any failure
 */
$loadJson = static function (?string $path): ?array {
    if ($path === null || !is_file($path)) {
        return null;
    }
    try {
        /** @psalm-var mixed $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    } catch (\JsonException) {
        return null;
    }
};

/**
 * Stable identity for an issue. NUL-joined so it is a safe array key.
 *
 * @param array<string, mixed> $i
 */
$issueKey = static function (array $i): string {
    return implode("\x00", [
        (string) ($i['file_path'] ?? ''),
        (string) ($i['line_from'] ?? 0),
        (string) ($i['line_to'] ?? 0),
        (string) ($i['type'] ?? '?'),
        (string) ($i['message'] ?? ''),
    ]);
};

/**
 * @var list<array{
 *     app: string,
 *     total: int,
 *     added: int,
 *     removed: int,
 *     net: int,
 *     movements: list<array{type: string, base: int, head: int, delta: int}>,
 * }> $rows
 */
$rows = [];
/** @var list<string> $missing */
$missing = [];

foreach ($apps as $app) {
    $baseIssues = $loadJson($latest($outputDir, $app, $baseLabel, 'issues.json', $dateMarker));
    $headIssues = $loadJson($latest($outputDir, $app, $headLabel, 'issues.json', $dateMarker));

    if (!is_array($baseIssues) || !array_is_list($baseIssues)
        || !is_array($headIssues) || !array_is_list($headIssues)) {
        $missing[] = $app;
        continue;
    }

    // Map issue identity -> type (type only: keeping the full issue, with its
    // multi-line code snippet, would hold ~100 MB on a 20k-issue app). Keyed by
    // identity so duplicates collapse and the per-type counts below reconcile
    // with the +/- churn. The foreach @var types each decoded issue (mixed JSON).
    $baseByKey = [];
    /** @var array<string, mixed> $i */
    foreach ($baseIssues as $i) {
        $baseByKey[$issueKey($i)] = (string) ($i['type'] ?? '?');
    }
    $headByKey = [];
    /** @var array<string, mixed> $i */
    foreach ($headIssues as $i) {
        $headByKey[$issueKey($i)] = (string) ($i['type'] ?? '?');
    }
    unset($baseIssues, $headIssues);

    $added = count(array_diff_key($headByKey, $baseByKey));
    $removed = count(array_diff_key($baseByKey, $headByKey));
    $net = count($headByKey) - count($baseByKey);

    // Per-type counts (deduped) for this app, base vs head, for the movement list.
    $baseTypeCount = array_count_values(array_values($baseByKey));
    $headTypeCount = array_count_values(array_values($headByKey));

    $movements = [];
    foreach (array_keys($baseTypeCount + $headTypeCount) as $type) {
        $b = $baseTypeCount[$type] ?? 0;
        $h = $headTypeCount[$type] ?? 0;
        if ($b !== $h) {
            $movements[] = ['type' => $type, 'base' => $b, 'head' => $h, 'delta' => $h - $b];
        }
    }
    // Signed delta ascending: biggest reductions first, regressions last.
    usort(
        $movements,
        /**
         * @param array{type: string, base: int, head: int, delta: int} $x
         * @param array{type: string, base: int, head: int, delta: int} $y
         */
        static fn(array $x, array $y): int => [$x['delta'], $x['type']] <=> [$y['delta'], $y['type']],
    );

    $rows[] = [
        'app' => $app,
        'total' => $added + $removed,
        'added' => $added,
        'removed' => $removed,
        'net' => $net,
        'movements' => $movements,
    ];
}

// --- Header ------------------------------------------------------------------

$baseDesc = $baseRef !== '' ? $baseRef : $baseLabel;
$headDesc = $headRef !== '' ? $headRef : $headLabel;
if ($baseSha !== '') {
    $baseDesc = "{$baseDesc} ({$baseSha})";
}
if ($headSha !== '') {
    $headDesc = "{$headDesc} ({$headSha})";
}

$out = [];
$out[] = "# PR delta: {$baseDesc} -> {$headDesc}";
$out[] = '';

// --- Per-app delta table (changed apps only) --------------------------------
//
// Columns: Total (issues touched = added + removed), + (added), − (removed),
// Δ (net = head − base). Only apps with at least one changed issue appear.

$changed = array_values(array_filter($rows, static fn(array $r): bool => $r['total'] > 0));

$out[] = '## Per-app delta';
$out[] = '';
if ($changed === []) {
    $out[] = 'No issue changes across the benchmarked apps.';
} else {
    $out[] = '| App | Total | + | − | Δ |';
    $out[] = '|-----|------:|----:|----:|-----:|';
    $tTotal = 0;
    $tAdded = 0;
    $tRemoved = 0;
    $tNet = 0;
    foreach ($changed as $r) {
        $out[] = sprintf('| %s | %d | %d | %d | %+d |', $r['app'], $r['total'], $r['added'], $r['removed'], $r['net']);
        $tTotal += $r['total'];
        $tAdded += $r['added'];
        $tRemoved += $r['removed'];
        $tNet += $r['net'];
    }
    $out[] = sprintf('| **Total** | **%d** | **%d** | **%d** | **%+d** |', $tTotal, $tAdded, $tRemoved, $tNet);

    // Per-app issue-type movements: every type whose count changed, base -> head.
    foreach ($changed as $r) {
        $out[] = '';
        $out[] = '### ' . $r['app'];
        $out[] = '';
        foreach ($r['movements'] as $m) {
            $out[] = sprintf('- %s: %d -> %d (%+d)', $m['type'], $m['base'], $m['head'], $m['delta']);
        }
    }
}

if ($missing !== []) {
    $out[] = '';
    $out[] = '> No data (crashed or not run): ' . implode(', ', $missing) . '.';
}

echo implode("\n", $out) . "\n";
exit(0);
