<?php

declare(strict_types=1);

/**
 * Build a base-vs-head issue-delta report (markdown) from per-app Psalm JSON.
 *
 * Reads issues.json + perf.json files produced by delta-app.sh out of an
 * OUTPUT_DIR, matches base/head pairs per app, and prints a delta-only report:
 *
 *   * a per-app table of changed apps (total touched, +added, -removed, net Δ)
 *   * per changed app, the issue-type breakdown (base -> head, +added/-removed)
 *   * apps that ran clean with zero delta, and apps that crashed (with the
 *     crash's first error line) — kept in separate buckets
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
//
// memory_limit is a shorthand byte string ("256M", "2G", "-1"), so a naive
// (int) cast reads "2G" as 2 and would *lower* a 2 GB limit to 1 GB. Parse the
// K/M/G unit before comparing.
$parseBytes = static function (string $value): int {
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return -1;
    }
    $unit = strtolower($value[strlen($value) - 1]);
    $number = (int) $value;
    return match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => (int) $value,
    };
};
$currentLimit = $parseBytes((string) ini_get('memory_limit'));
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
 * First meaningful error line from an app's crash log (either side), or null
 * when the app left no crash log (it never reached the analysis step — e.g. a
 * Composer install failure — or simply did not run).
 *
 * delta-app.sh writes "<app>-<label>-<marker>--crash.log" as:
 *   === <app>/<label> exit N after Ms ===
 *   --- stderr ---
 *   <the Psalm/Composer error>          <- this line
 *   --- stdout ---
 * The trailing " in /path:line" is dropped so the message stays readable.
 */
$crashExcerpt = static function (string $app) use ($latest, $outputDir, $baseLabel, $headLabel, $dateMarker): ?string {
    foreach ([$baseLabel, $headLabel] as $label) {
        $path = $latest($outputDir, $app, $label, 'crash.log', $dateMarker);
        if ($path === null || !is_file($path)) {
            continue;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            continue;
        }
        $inStderr = false;
        foreach ($lines as $line) {
            $text = trim($line);
            if ($text === '--- stderr ---') {
                $inStderr = true;
                continue;
            }
            if ($text === '--- stdout ---') {
                break;
            }
            if ($inStderr && $text !== '') {
                $cut = strpos($text, ' in /');
                if ($cut !== false) {
                    $text = substr($text, 0, $cut);
                }

                return mb_strimwidth(str_replace('`', "'", $text), 0, 300, '…');
            }
        }
    }

    return null;
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
 *     movements: list<array{type: string, base: int, head: int, added: int, removed: int, delta: int}>,
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

    // Per-type base/head totals (deduped) for the "base -> head" display.
    $baseTypeCount = array_count_values(array_values($baseByKey));
    $headTypeCount = array_count_values(array_values($headByKey));

    // Per-type ADDED / REMOVED identity counts. Counting net totals alone hides
    // churn: a type with the same count on both sides but different identities
    // (a fixed issue replaced by a new one of the same type) nets to zero yet is
    // a real movement. Diffing identities surfaces it as +x/-y.
    $addedByType = [];
    foreach (array_diff_key($headByKey, $baseByKey) as $type) {
        $addedByType[$type] = ($addedByType[$type] ?? 0) + 1;
    }
    $removedByType = [];
    foreach (array_diff_key($baseByKey, $headByKey) as $type) {
        $removedByType[$type] = ($removedByType[$type] ?? 0) + 1;
    }

    $movements = [];
    foreach (array_keys($baseTypeCount + $headTypeCount) as $type) {
        $a = $addedByType[$type] ?? 0;
        $r = $removedByType[$type] ?? 0;
        if ($a === 0 && $r === 0) {
            continue; // identical identities on both sides — nothing moved
        }
        $movements[] = [
            'type' => $type,
            'base' => $baseTypeCount[$type] ?? 0,
            'head' => $headTypeCount[$type] ?? 0,
            'added' => $a,
            'removed' => $r,
            'delta' => ($headTypeCount[$type] ?? 0) - ($baseTypeCount[$type] ?? 0),
        ];
    }
    // Signed net delta ascending (biggest reductions first, regressions last);
    // tie-break by churn volume so pure churn sorts by size, then by type name.
    usort(
        $movements,
        /**
         * @param array{type: string, base: int, head: int, added: int, removed: int, delta: int} $x
         * @param array{type: string, base: int, head: int, added: int, removed: int, delta: int} $y
         */
        static fn(array $x, array $y): int => [$x['delta'], -($x['added'] + $x['removed']), $x['type']]
            <=> [$y['delta'], -($y['added'] + $y['removed']), $y['type']],
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

// Per-app wall-time + type coverage, collected for EVERY app (not only changed
// ones): a PR can shift analysis time or coverage without moving any issue.
// Both come from each side's perf.json; a side that crashed left no perf.json,
// so its value is null and renders "—".
$perfNum = static function (?array $perf, string $key): ?float {
    return is_array($perf) && isset($perf[$key]) && is_numeric($perf[$key])
        ? (float) $perf[$key]
        : null;
};
/** @var list<array{app: string, baseWall: float|null, headWall: float|null, baseCov: float|null, headCov: float|null}> $perfRows */
$perfRows = [];
foreach ($apps as $app) {
    $basePerf = $loadJson($latest($outputDir, $app, $baseLabel, 'perf.json', $dateMarker));
    $headPerf = $loadJson($latest($outputDir, $app, $headLabel, 'perf.json', $dateMarker));
    $perfRows[] = [
        'app' => $app,
        'baseWall' => $perfNum($basePerf, 'wall_seconds'),
        'headWall' => $perfNum($headPerf, 'wall_seconds'),
        'baseCov' => $perfNum($basePerf, 'type_coverage_pct'),
        'headCov' => $perfNum($headPerf, 'type_coverage_pct'),
    ];
}

// --- Header ------------------------------------------------------------------

/**
 * Build a heading description like "Base (7fb82df7)" for one side.
 *
 * The artifact label is "<prefix>-<shortsha>" (e.g. "base-7fb82df7"), kept
 * verbatim for file matching — so reusing it as the heading would print the
 * embedded short sha *and* the separate --*-sha, e.g. "base-7fb82df7
 * (7fb82df70a30…)". Prefer an explicit ref; otherwise strip the trailing
 * "-<sha>" off the label to recover the prefix word, tidy its casing, and
 * append a single short sha (skipped when the word already carries it).
 */
$describe = static function (string $ref, string $label, string $sha): string {
    $word = $ref !== '' ? $ref : (preg_replace('/-[0-9a-f]{7,40}$/', '', $label) ?? $label);
    $word = match (strtolower($word)) {
        'base' => 'Base',
        'pr' => 'PR',
        default => $word,
    };
    if ($sha !== '') {
        $short = substr($sha, 0, 8);
        if (!str_contains($word, $short)) {
            $word .= " ({$short})";
        }
    }

    return $word;
};
$baseDesc = $describe($baseRef, $baseLabel, $baseSha);
$headDesc = $describe($headRef, $headLabel, $headSha);

$out = [];
$out[] = "## PR delta: {$baseDesc} -> {$headDesc}";
$out[] = '';

// --- Per-app delta table (changed apps only) --------------------------------
//
// Columns: Total (issues touched = added + removed), + (added), − (removed),
// Δ (net = head − base). Only apps with at least one changed issue appear.

$changed = array_values(array_filter($rows, static fn(array $r): bool => $r['total'] > 0));

$out[] = '### Per-app delta — Issues';
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

    // Per-app issue-type movements, all under ONE collapsible block so the
    // comment stays compact regardless of how many apps changed: per app, the
    // base -> head count with the +added/-removed identity churn a net-count
    // diff alone would hide.
    $out[] = '';
    $out[] = '<details><summary>Per-app issue-type breakdown</summary>';
    foreach ($changed as $r) {
        $out[] = '';
        $out[] = sprintf('#### %s (+%d/-%d)', $r['app'], $r['added'], $r['removed']);
        $out[] = '';
        foreach ($r['movements'] as $m) {
            $out[] = sprintf(
                '- %s: %d -> %d (+%d/-%d)',
                $m['type'],
                $m['base'],
                $m['head'],
                $m['added'],
                $m['removed'],
            );
        }
    }
    $out[] = '';
    $out[] = '</details>';
}

// Apps that ran cleanly on both sides but produced no delta — listed here under
// Issues (with the issue results) rather than down by the perf tables, and kept
// separate from crashes so "nothing changed" is never read as "nothing ran".
$noChange = array_values(array_filter(
    $rows,
    static fn(array $r): bool => $r['total'] === 0,
));
if ($noChange !== []) {
    $names = array_map(static fn(array $r): string => $r['app'], $noChange);
    $out[] = '';
    $out[] = '> No change (ran clean, zero delta): ' . implode(', ', $names) . '.';
}

// --- Per-app time table (apps with a non-noise time move) -------------------
//
// Wall time on shared CI runners is noisy (process scheduling, IO): identical
// runs vary by a second or two, so a raw Δ is mostly jitter, not PR impact.
// Show only apps whose |Δ| clears TIME_NOISE_FLOOR; skip non-comparable sides
// (crash / not run — already in their own buckets). Empty -> a one-liner.
$timeNoiseFloor = 3.0; // seconds; below this a Δ is treated as runner jitter
$timeLines = [];
foreach ($perfRows as $p) {
    if ($p['baseWall'] === null || $p['headWall'] === null) {
        continue;
    }
    $timeDelta = $p['headWall'] - $p['baseWall'];
    if (abs($timeDelta) < $timeNoiseFloor) {
        continue;
    }
    $timeLines[] = sprintf('| %s | %.2f | %.2f | %+.2f |', $p['app'], $p['baseWall'], $p['headWall'], $timeDelta);
}

$out[] = '';
$out[] = '### Per-app delta — Time (seconds)';
$out[] = '';
if ($timeLines === []) {
    $out[] = 'No significant time change (all deltas within runner jitter).';
} else {
    $out[] = '| App | base | PR | Δ |';
    $out[] = '|-----|-----:|----:|-----:|';
    foreach ($timeLines as $timeLine) {
        $out[] = $timeLine;
    }
    $out[] = '';
    $out[] = sprintf(
        '_Wall time on shared CI runners is noisy (±1–2s run-to-run); deltas under ±%.0fs are hidden as jitter, not PR impact._',
        $timeNoiseFloor,
    );
}

// --- Per-app type-coverage table (apps whose coverage moved) ----------------
//
// Psalm's inferred-type coverage %, base vs head (Δ = head − base). A PR can
// move coverage without moving any issue (e.g. adding annotations). Only apps
// whose coverage actually moved are listed: unchanged rows (Δ rounds to 0.00)
// are hidden, and a side with no perf.json (crash / not run) is skipped here
// since it already appears in the Crashed / Not-run buckets.

$covLines = [];
foreach ($perfRows as $p) {
    if ($p['baseCov'] === null || $p['headCov'] === null) {
        continue; // not comparable — surfaced in its own bucket, not here
    }
    $delta = sprintf('%+.2f', $p['headCov'] - $p['baseCov']);
    if ($delta === '+0.00' || $delta === '-0.00') {
        continue; // coverage unchanged
    }
    $covLines[] = sprintf('| %s | %.2f | %.2f | %s |', $p['app'], $p['baseCov'], $p['headCov'], $delta);
}

$out[] = '';
$out[] = '### Per-app delta — Type coverage (%)';
$out[] = '';
if ($covLines === []) {
    $out[] = 'No type-coverage changes.';
} else {
    $out[] = '| App | base | PR | Δ |';
    $out[] = '|-----|-----:|----:|-----:|';
    foreach ($covLines as $covLine) {
        $out[] = $covLine;
    }
}

// Weighted ΔCov: one headline coverage move across all apps. A plain mean of
// the per-app Δ would weight a one-file lib equal to a 5k-file app, so each
// app's Δ is weighted by its base wall_seconds (analysis time — a cheap proxy
// for codebase size; perf.json carries no file count). Only apps with coverage
// on both sides and a positive base wall contribute; the total can therefore
// differ from a naive sum of the Δ column.
$weightNum = 0.0;
$weightDen = 0.0;
foreach ($perfRows as $p) {
    if ($p['baseCov'] === null || $p['headCov'] === null || $p['baseWall'] === null || $p['baseWall'] <= 0) {
        continue;
    }
    $weightNum += ($p['headCov'] - $p['baseCov']) * (float) $p['baseWall'];
    $weightDen += (float) $p['baseWall'];
}
// Only as a footer row of the coverage table, and only when something moved —
// otherwise it would dangle as a header-less table row under "No changes".
if ($covLines !== [] && $weightDen > 0.0) {
    $out[] = sprintf('| **Weighted Δ** | — | — | **%+.2f** |', $weightNum / $weightDen);
    $out[] = '';
    $out[] = '_Weighted Δ is weighted by base `wall_seconds` (proxy for codebase size)._';
}

// Split "no issues.json" apps into genuine crashes (a crash log exists, shown
// with its error) vs apps that never ran / left no artifact.
if ($missing !== []) {
    /** @var array<string, string> $crashed */
    $crashed = [];
    /** @var list<string> $notRun */
    $notRun = [];
    foreach ($missing as $app) {
        $excerpt = $crashExcerpt($app);
        if ($excerpt !== null) {
            $crashed[$app] = $excerpt;
        } else {
            $notRun[] = $app;
        }
    }

    if ($crashed !== []) {
        $out[] = '';
        $out[] = '### Crashed';
        $out[] = '';
        $out[] = 'No usable report — Psalm crashed or analysis aborted. A crash on BOTH sides is not caused by this PR.';
        $out[] = '';
        foreach ($crashed as $app => $excerpt) {
            $out[] = "- **{$app}**: `{$excerpt}`";
        }
    }

    if ($notRun !== []) {
        $out[] = '';
        $out[] = '> Not run (install failed or no artifact): ' . implode(', ', $notRun) . '.';
    }
}

echo implode("\n", $out) . "\n";
exit(0);
