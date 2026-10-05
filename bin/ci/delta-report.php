<?php

declare(strict_types=1);

/**
 * Build a base-vs-head issue-delta report (markdown) from per-app Psalm JSON.
 *
 * Reads issues.json + perf.json files produced by delta-app.sh out of an
 * OUTPUT_DIR, matches base/head pairs per app, and prints a delta-only report:
 *
 *   * a per-app table of changed apps (total touched, +added, -removed,
 *     message changed, net Δ)
 *   * per changed app, the issue-type breakdown (base -> head, +added/-removed);
 *     with --details, also the changed, added and removed entries (file paths
 *     + issue messages)
 *   * apps that ran clean with zero delta, and apps that crashed (tagged with
 *     the crashing side) — kept in separate buckets
 *
 * File layout (written by delta-app.sh, identical to bench.sh):
 *   <output_dir>/<app>/<app>-<label>-<date-marker>--issues.json
 *   <output_dir>/<app>/<app>-<label>-<date-marker>--perf.json
 *
 * Issue identity is the multiset of (file_path, line_from, line_to,
 * column_from, column_to, type, message), so a PR fixing 50 issues and
 * introducing 50 new ones reports +50/-50 instead of ΔNet=0. Leftover
 * removed/added entries on the same file, line_from and type with a different
 * message are paired as "changed" and leave the +/- totals.
 *
 * Usage:
 *   php delta-report.php <output_dir> <base_label> <head_label> \
 *       --apps=monica,pixelfed,coolify \
 *       [--base-ref=] [--head-ref=] [--base-sha=] [--head-sha=] \
 *       [--date-marker=cache] [--details] [--selection='default + octane']
 *
 * --details prints the changed, added and removed entries and crash text. They
 * carry file paths and issue messages, so they are OFF by default (safe for a
 * private app).
 *
 * --selection is the resolved /psalm-delta selector label (bin/ci/delta-select-apps.php),
 * printed under the header with the app count.
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
$currentLimit = $parseBytes(ini_get('memory_limit'));
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
    fwrite(STDERR, "Usage: php delta-report.php <output_dir> <base_label> <head_label> --apps=a,b,c [--base-ref=] [--head-ref=] [--base-sha=] [--head-sha=] [--date-marker=cache] [--details] [--selection=label]\n");
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
$details = isset($options['details']);
$selection = $options['selection'] ?? '';

// Issue types whose presence depends on the order Psalm merges parallel workers
// (first-merge-wins CodeUseGraph::$mutation_info after the thread pool join),
// not on the code: excluded from +/−/Changed/Δ.
const ORDER_DEPENDENT_TYPES = ['MissingPureAnnotation'];

// --details entry lists: at most DETAILS_CAP entries per list per app, and no
// further app's entries once the report passes DETAILS_BUDGET bytes, so a
// churny all-apps run stays under GitHub's 65,536-char comment limit.
const DETAILS_CAP = 10;
const DETAILS_BUDGET = 40_000;

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
        $dateGlob = '\d\d\d\d-\d\d-\d\d';
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
 * First stderr line of each side's crash log (side => excerpt); a side without
 * a crash log is absent (never reached analysis, or did not run).
 *
 * delta-app.sh writes "<app>-<label>-<marker>--crash.log" as:
 *   === <app>/<label> exit N after Ms ===
 *   --- stderr ---
 *   <the Psalm/Composer error>          <- this line
 *   --- stdout ---
 * The trailing " in /path:line" is dropped so the message stays readable.
 */
$crashExcerpt = static function (string $app) use ($latest, $outputDir, $baseLabel, $headLabel, $dateMarker): array {
    $sides = [];
    foreach (['base' => $baseLabel, 'head' => $headLabel] as $side => $label) {
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

                $sides[$side] = mb_strimwidth(str_replace('`', "'", $text), 0, 300, '…');
                break;
            }
        }
    }

    return $sides;
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
        (string) ($i['column_from'] ?? 0),
        (string) ($i['column_to'] ?? 0),
    ]);
};

/**
 * One side's issues as [stable key => count, stable type => count,
 * type => error_level, order-dependent key => count]. Only keys and counts are
 * kept: the full issue, with its code snippet, would hold ~100 MB on a
 * 20k-issue app.
 *
 * @param list<mixed> $issues
 * @return array{0: array<string, int>, 1: array<string, int>, 2: array<string, int>, 3: array<string, int>}
 */
$tally = static function (array $issues) use ($issueKey): array {
    $keys = [];
    $types = [];
    $levels = [];
    $order = [];
    /** @var array<string, mixed> $i */
    foreach ($issues as $i) {
        $type = (string) ($i['type'] ?? '?');
        $key = $issueKey($i);
        if (in_array($type, ORDER_DEPENDENT_TYPES, true)) {
            $order[$key] = ($order[$key] ?? 0) + 1;
            continue;
        }

        $keys[$key] = ($keys[$key] ?? 0) + 1;
        $types[$type] = ($types[$type] ?? 0) + 1;
        // Psalm error level at which the type surfaces; stable per type.
        if (isset($i['error_level']) && is_numeric($i['error_level'])) {
            $levels[$type] = (int) $i['error_level'];
        }
    }

    return [$keys, $types, $levels, $order];
};

/**
 * Keys whose count is higher in $from than in $against, with the surplus.
 *
 * @param array<string, int> $from
 * @param array<string, int> $against
 * @return array<string, int>
 */
$surplus = static function (array $from, array $against): array {
    $diff = [];
    foreach ($from as $key => $n) {
        if ($n > ($against[$key] ?? 0)) {
            $diff[$key] = $n - ($against[$key] ?? 0);
        }
    }

    return $diff;
};

/**
 * Sum key counts per issue type (key part 3).
 *
 * @param array<string, int> $counts
 * @return array<string, int>
 */
$byType = static function (array $counts): array {
    $sums = [];
    foreach ($counts as $key => $n) {
        $type = explode("\x00", $key)[3];
        $sums[$type] = ($sums[$type] ?? 0) + $n;
    }

    return $sums;
};

/**
 * One entry per key occurrence, sorted by file, numeric line, type, message.
 *
 * @param array<string, int> $counts
 * @return list<array{type: string, loc: string, message: string}>
 */
$entries = static function (array $counts): array {
    $list = [];
    foreach ($counts as $key => $n) {
        $p = explode("\x00", $key);
        for ($k = 0; $k < $n; $k++) {
            $list[] = [$p[0], (int) $p[1], $p[3], $p[4]];
        }
    }

    sort($list);

    return array_map(static fn(array $e): array => ['type' => $e[2], 'loc' => "{$e[0]}:{$e[1]}", 'message' => $e[3]], $list);
};

/** Markdown inline-code span of a message: one line, clipped. */
$code = static fn(string $s): string => '`' . str_replace('`', "'", mb_strimwidth((string) preg_replace('/\s+/u', ' ', $s), 0, 300, '…')) . '`';

/**
 * @var list<array{
 *     app: string,
 *     total: int,
 *     added: int,
 *     removed: int,
 *     changed: list<array{type: string, loc: string, old: string, new: string}>,
 *     addedList: list<array{type: string, loc: string, message: string}>,
 *     removedList: list<array{type: string, loc: string, message: string}>,
 *     net: int,
 *     movements: list<array{type: string, level: int|null, base: int, head: int, added: int, removed: int, changed: int, delta: int}>,
 *     volatile: list<string>,
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

    [$baseKeys, $baseTypes, $baseLevels, $baseOrder] = $tally($baseIssues);
    [$headKeys, $headTypes, $headLevels, $headOrder] = $tally($headIssues);
    unset($baseIssues, $headIssues);

    // head last: the post-PR level wins on cross-side disagreement.
    $typeLevel = $headLevels + $baseLevels;
    $removed = $surplus($baseKeys, $headKeys);
    $added = $surplus($headKeys, $baseKeys);
    unset($baseKeys, $headKeys);

    // Pair leftovers at the same file, line_from and type with a different
    // message. Sorted, so the pairing is deterministic.
    $group = static fn(array $p): string => "{$p[0]}\x00{$p[1]}\x00{$p[3]}";
    ksort($removed);
    ksort($added);
    $addedByGroup = [];
    foreach (array_keys($added) as $key) {
        $addedByGroup[$group(explode("\x00", $key))][] = $key;
    }

    $changed = [];
    foreach (array_keys($removed) as $oldKey) {
        $o = explode("\x00", $oldKey);
        foreach ($addedByGroup[$group($o)] ?? [] as $newKey) {
            $n = explode("\x00", $newKey);
            while ($removed[$oldKey] > 0 && $added[$newKey] > 0 && $n[4] !== $o[4]) {
                $changed[] = [
                    'type' => $o[3],
                    'loc' => "{$o[0]}:{$o[1]}",
                    'old' => $o[4],
                    'new' => $n[4],
                ];
                $removed[$oldKey]--;
                $added[$newKey]--;
            }
        }
    }

    $removed = array_filter($removed);
    $added = array_filter($added);

    // Per-type identity counts: net totals alone hide churn (a fixed issue
    // replaced by a new one of the same type nets to zero).
    $addedByType = $byType($added);
    $removedByType = $byType($removed);
    $changedByType = array_count_values(array_column($changed, 'type'));

    $movements = [];
    foreach (array_keys($baseTypes + $headTypes) as $type) {
        $a = $addedByType[$type] ?? 0;
        $r = $removedByType[$type] ?? 0;
        $c = $changedByType[$type] ?? 0;
        if ($a === 0 && $r === 0 && $c === 0) {
            continue; // identical identities on both sides — nothing moved
        }

        $movements[] = [
            'type' => $type,
            'level' => $typeLevel[$type] ?? null,
            'base' => $baseTypes[$type] ?? 0,
            'head' => $headTypes[$type] ?? 0,
            'added' => $a,
            'removed' => $r,
            'changed' => $c,
            'delta' => ($headTypes[$type] ?? 0) - ($baseTypes[$type] ?? 0),
        ];
    }

    // Signed net delta ascending (biggest reductions first, regressions last);
    // tie-break by churn volume so pure churn sorts by size, then by type name.
    usort(
        $movements,
        /**
         * @param array{type: string, added: int, removed: int, changed: int, delta: int} $x
         * @param array{type: string, added: int, removed: int, changed: int, delta: int} $y
         */
        static fn(array $x, array $y): int => [$x['delta'], -($x['added'] + $x['removed'] + $x['changed']), $x['type']]
            <=> [$y['delta'], -($y['added'] + $y['removed'] + $y['changed']), $y['type']],
    );

    $orderAdded = $byType($surplus($headOrder, $baseOrder));
    $orderRemoved = $byType($surplus($baseOrder, $headOrder));
    $volatile = [];
    foreach (ORDER_DEPENDENT_TYPES as $type) {
        if (isset($orderAdded[$type]) || isset($orderRemoved[$type])) {
            $volatile[] = sprintf('%s +%d/−%d', $type, $orderAdded[$type] ?? 0, $orderRemoved[$type] ?? 0);
        }
    }

    $rows[] = [
        'app' => $app,
        'total' => array_sum($added) + array_sum($removed) + count($changed),
        'added' => array_sum($added),
        'removed' => array_sum($removed),
        'changed' => $changed,
        'addedList' => $details ? $entries($added) : [],
        'removedList' => $details ? $entries($removed) : [],
        'net' => array_sum($headTypes) - array_sum($baseTypes),
        'movements' => $movements,
        'volatile' => $volatile,
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
if ($selection !== '') {
    $out[] = sprintf('Apps: %s → %d', $selection, count($apps));
    $out[] = '';
}

// --- Per-app delta table (changed apps only) --------------------------------
//
// Columns: Total (issues touched = added + removed + changed), + (added),
// − (removed), Changed (same place and type, new message), Δ (net = head −
// base, unaffected by changed). Only apps with at least one change appear.

$changed = array_values(array_filter($rows, static fn(array $r): bool => $r['total'] > 0));

$out[] = '### Per-app delta — Issues';
$out[] = '';
if ($changed === []) {
    $out[] = 'No issue changes across the benchmarked apps.';
} else {
    $out[] = '| App | Total | + | − | Changed | Δ |';
    $out[] = '|-----|------:|----:|----:|----:|-----:|';
    $tTotal = 0;
    $tAdded = 0;
    $tChanged = 0;
    $tRemoved = 0;
    $tNet = 0;
    foreach ($changed as $r) {
        $out[] = sprintf('| %s | %d | %d | %d | %d | %+d |', $r['app'], $r['total'], $r['added'], $r['removed'], count($r['changed']), $r['net']);
        $tTotal += $r['total'];
        $tAdded += $r['added'];
        $tRemoved += $r['removed'];
        $tChanged += count($r['changed']);
        $tNet += $r['net'];
    }

    $out[] = sprintf('| **Total** | **%d** | **%d** | **%d** | **%d** | **%+d** |', $tTotal, $tAdded, $tRemoved, $tChanged, $tNet);

    // Per-app issue-type movements, all under ONE collapsible block so the
    // comment stays compact regardless of how many apps changed: per app, the
    // base -> head count with the +added/-removed identity churn a net-count
    // diff alone would hide.
    $out[] = '';
    $out[] = '<details><summary>Per-app issue-type breakdown</summary>';
    foreach ($changed as $r) {
        $out[] = '';
        $out[] = sprintf('#### %s (+%d/-%d, %d changed)', $r['app'], $r['added'], $r['removed'], count($r['changed']));
        $out[] = '';
        foreach ($r['movements'] as $m) {
            // Prefix the Psalm error level (e.g. "L3: ") so a reader can gauge
            // severity at a glance — lower levels are stricter/higher-signal.
            // Omitted only if the JSON carried no error_level for the type.
            $levelPrefix = $m['level'] !== null ? sprintf('L%d: ', $m['level']) : '';
            $out[] = sprintf(
                '- %s%s: %d -> %d (+%d/-%d, %d changed)',
                $levelPrefix,
                $m['type'],
                $m['base'],
                $m['head'],
                $m['added'],
                $m['removed'],
                $m['changed'],
            );
        }

        if (!$details) {
            continue;
        }

        if (strlen(implode("\n", $out)) > DETAILS_BUDGET) {
            $out[] = '';
            $out[] = '- … entries omitted (comment size limit)';
            continue;
        }

        $lines = [];
        foreach (array_slice($r['changed'], 0, DETAILS_CAP) as $c) {
            $lines[] = sprintf('- %s `%s`: %s → %s', $c['type'], $c['loc'], $code($c['old']), $code($c['new']));
        }

        if (count($r['changed']) > DETAILS_CAP) {
            $lines[] = sprintf('- … and %d more changed', count($r['changed']) - DETAILS_CAP);
        }

        // `- + X` renders as a nested bullet in GFM, swallowing the `+`.
        foreach (['\+' => $r['addedList'], '−' => $r['removedList']] as $sign => $list) {
            foreach (array_slice($list, 0, DETAILS_CAP) as $e) {
                $lines[] = sprintf('- %s %s `%s`: %s', $sign, $e['type'], $e['loc'], $code($e['message']));
            }

            if (count($list) > DETAILS_CAP) {
                $lines[] = sprintf('- %s … and %d more', $sign, count($list) - DETAILS_CAP);
            }
        }

        if ($lines !== []) {
            $out[] = '';
            array_push($out, ...$lines);
        }
    }

    $out[] = '';
    $out[] = '</details>';
}

foreach ($rows as $r) {
    if ($r['volatile'] !== []) {
        $out[] = '';
        $out[] = sprintf('> **%s**: order-dependent, excluded: %s', $r['app'], implode(', ', $r['volatile']));
    }
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
// move coverage without moving any issue (e.g. adding annotations).
//
// Psalm's parallel analysis is nondeterministic: which files share a worker
// changes how many non-mixed expressions get counted, so identical code and
// vendor measured pixelfed at 84.9672-84.9693% across 4-thread runs (serial
// runs repeat exactly, but cost ~2x wall time). Moves under $covNoiseFloor
// are that jitter, so they are dropped on the RAW Δ and the rest is shown at
// 2 decimals. Gating on the raw Δ, not on rounded values, keeps jitter that
// straddles a rounding boundary from surfacing as a phantom ±0.01. A side with
// no perf.json (crash / not run) is skipped here since it already appears in
// the Crashed / Not-run buckets.
$covNoiseFloor = 0.005; // percentage points; also the smallest move visible at 2 decimals

$covLines = [];
foreach ($perfRows as $p) {
    if ($p['baseCov'] === null || $p['headCov'] === null) {
        continue; // not comparable — surfaced in its own bucket, not here
    }

    $delta = $p['headCov'] - $p['baseCov'];
    if (abs($delta) < $covNoiseFloor) {
        continue;
    }

    $covLines[] = sprintf('| %s | %.2f | %.2f | %+.2f |', $p['app'], $p['baseCov'], $p['headCov'], $delta);
}

$out[] = '';
$out[] = '### Per-app delta — Type coverage (%)';
$out[] = '';
if ($covLines === []) {
    $out[] = 'No significant type-coverage change (all deltas within parallel-analysis jitter).';
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
    $out[] = sprintf(
        '_Weighted Δ is weighted by base `wall_seconds` (proxy for codebase size). Moves under ±%.3f are hidden as parallel-analysis jitter._',
        $covNoiseFloor,
    );
}

// Split "no issues.json" apps into genuine crashes (a crash log exists on at
// least one side, tagged with the side) vs apps that never ran / left no artifact.
if ($missing !== []) {
    /** @var array<string, array<string, string>> $crashed */
    $crashed = [];
    /** @var list<string> $notRun */
    $notRun = [];
    foreach ($missing as $app) {
        $sides = $crashExcerpt($app);
        if ($sides !== []) {
            $crashed[$app] = $sides;
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
        foreach ($crashed as $app => $sides) {
            $out[] = sprintf('- **%s** (%s)', $app, count($sides) === 2 ? 'both' : array_key_first($sides));
            // Exception text can name private classes or hosts: opt-in only.
            if ($details) {
                foreach ($sides as $side => $excerpt) {
                    $out[] = "  - {$side}: `{$excerpt}`";
                }
            }
        }
    }

    if ($notRun !== []) {
        $out[] = '';
        $out[] = '> Not run (install failed or no artifact): ' . implode(', ', $notRun) . '.';
    }
}

echo implode("\n", $out) . "\n";
exit(0);
