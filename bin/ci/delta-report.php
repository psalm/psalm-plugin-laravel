<?php

declare(strict_types=1);

/**
 * Build a base-vs-head issue-delta report (markdown) from per-app Psalm JSON.
 *
 * Reads issues.json + perf.json (+ crash.log) files produced by delta-app.sh
 * out of an OUTPUT_DIR, matches base/head pairs per app, and prints a
 * delta-only report:
 *
 *   * a warnings block when a side is not comparable (plugin degraded/disabled,
 *     different dependency/Psalm/PHP versions or thread counts)
 *   * a per-app table of changed apps (+added, -removed, message changed,
 *     moved, net Δ)
 *   * per changed app, the issue-type breakdown (counts only); with --details,
 *     also the changed/moved entries (file paths + issue messages)
 *   * apps that ran with zero delta, and apps that crashed (tagged with the
 *     crashing side) — kept in separate buckets
 *
 * File layout (written by delta-app.sh, identical to bench.sh):
 *   <output_dir>/<app>/<app>-<label>-<date-marker>--issues.json
 *   <output_dir>/<app>/<app>-<label>-<date-marker>--perf.json
 *   <output_dir>/<app>/<app>-<label>-<date-marker>--crash.log   (no usable report)
 *
 * perf.json fields beyond wall/coverage are all optional (older artifacts
 * lack them): threads (int), plugin_status ("ok"|"degraded"|"disabled"),
 * versions ({php, vimeo/psalm, laravel/framework}), deps_diverged (bool).
 *
 * Issue identity is the multiset of (file, line_from, line_to, column_from,
 * column_to, type, message): a PR fixing 50 issues and introducing 50 new ones
 * reports +50/-50 instead of ΔNet=0, and a second identical-looking issue on a
 * line still counts. Leftover removed/added entries are then paired:
 *   1. same location + type + message, other column  -> "moved"
 *   2. same file/line_from + type, different message -> "message changed"
 *   3. same type + message, different location       -> "moved"
 * Pairs are neutral churn, so they leave the +/- totals; Δ stays head - base.
 *
 * The body is capped below GitHub's 65536-BYTE comment limit.
 *
 * Usage:
 *   php delta-report.php <output_dir> <base_label> <head_label> \
 *       --apps=monica,pixelfed,coolify \
 *       [--base-ref=] [--head-ref=] [--base-sha=] [--head-sha=] \
 *       [--date-marker=cache] [--top=10] [--details]
 *
 * --top caps the changed/moved entries listed per app (rest become "… N more").
 * --details prints those per-issue entries. They carry file paths and issue
 * messages, so they are OFF by default: without the flag the output holds only
 * aggregate counts (safe to publish for a private app). Pass it only for
 * public apps (the CI registry).
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
    fwrite(STDERR, "Usage: php delta-report.php <output_dir> <base_label> <head_label> --apps=a,b,c [--base-ref=] [--head-ref=] [--base-sha=] [--head-sha=] [--date-marker=cache] [--top=10] [--details]\n");
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
$top = max(1, (int) ($options['top'] ?? 10));
$showDetails = isset($options['details']);

// GitHub rejects comment bodies over 65536 BYTES (not characters). The workflow
// prepends a marker and appends a footer (< 500 bytes), so stay well below it.
const MAX_BODY_BYTES = 60000;
// A message's differing part can sit past any prefix cut, so long messages are
// shown as a window around the first difference, not as a head slice.
const MESSAGE_LIMIT = 300;
const DIFF_CONTEXT = 60;

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
 * First meaningful error line from one side's crash log, or null when that
 * side left no crash log (it never reached the analysis step or did not run).
 *
 * delta-app.sh writes "<app>-<label>-<marker>--crash.log" as:
 *   === <app>/<label> exit N after Ms ===
 *   --- stderr ---
 *   <the Psalm/Composer error>          <- this line
 *   --- stdout ---
 * The trailing " in /path:line" is dropped so the message stays readable. A log
 * without a stderr body (e.g. a composer failure) still marks the side as
 * crashed: the first non-marker line, else the header, stands in.
 */
$crashExcerpt = static function (string $app, string $label) use ($latest, $outputDir, $dateMarker): ?string {
    $path = $latest($outputDir, $app, $label, 'crash.log', $dateMarker);
    if ($path === null || !is_file($path)) {
        return null;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false || $lines === []) {
        return '(empty crash log)';
    }

    $inStderr = false;
    $fallback = null;
    foreach ($lines as $line) {
        $text = trim($line);
        if ($text === '--- stderr ---') {
            $inStderr = true;
            continue;
        }

        if ($text === '--- stdout ---') {
            $inStderr = false;
            continue;
        }

        if ($text === '') {
            continue;
        }

        if ($inStderr) {
            $cut = strpos($text, ' in /');
            if ($cut !== false) {
                $text = substr($text, 0, $cut);
            }

            return mb_strimwidth(str_replace('`', "'", $text), 0, 300, '…');
        }

        $fallback ??= $text;
    }

    return mb_strimwidth(str_replace('`', "'", trim($fallback ?? $lines[0])), 0, 300, '…');
};

/**
 * Stable identity for an issue, NUL-joined so it is a safe array key and can be
 * split back with explode(): [file, line_from, line_to, type, message, col_from, col_to].
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
 * Multiset of one side's issues. Only keys and per-type counts are kept: the
 * full issue, with its multi-line code snippet, would hold ~100 MB on a
 * 20k-issue app.
 *
 * @param list<mixed> $issues
 * @return array{0: array<string, int>, 1: array<string, int>, 2: array<string, int>}  [key => count, type => count, type => error_level]
 */
$tally = static function (array $issues) use ($issueKey): array {
    $counts = [];
    $types = [];
    $levels = [];
    /** @var array<string, mixed> $i */
    foreach ($issues as $i) {
        $key = $issueKey($i);
        $counts[$key] = ($counts[$key] ?? 0) + 1;
        $type = (string) ($i['type'] ?? '?');
        $types[$type] = ($types[$type] ?? 0) + 1;
        // A type's level (the config level at which it surfaces) is stable
        // across its issues, so last-write-wins is fine.
        if (isset($i['error_level']) && is_numeric($i['error_level'])) {
            $levels[$type] = (int) $i['error_level'];
        }
    }

    return [$counts, $types, $levels];
};

/**
 * Greedily pair leftover removed/added entries that share a group, consuming
 * them from both maps (key => remaining count). One pair per unit of count.
 *
 * @param array<string, int> $removed
 * @param array<string, int> $added
 * @param list<int> $groupParts  issue-key parts that define the group
 * @return list<array{0: string, 1: string}>  [removedKey, addedKey]
 */
$pairUp = static function (array &$removed, array &$added, array $groupParts): array {
    $groupOf = static fn(string $key): string => implode("\x00", array_intersect_key(
        explode("\x00", $key),
        array_flip($groupParts),
    ));

    ksort($removed);
    ksort($added);
    $addedByGroup = [];
    foreach (array_keys($added) as $key) {
        $addedByGroup[$groupOf($key)][] = $key;
    }

    $pairs = [];
    foreach ($removed as $removedKey => $left) {
        $group = $groupOf($removedKey);
        while ($left > 0 && !empty($addedByGroup[$group])) {
            $addedKey = $addedByGroup[$group][0];
            $take = min($left, $added[$addedKey]);
            for ($n = 0; $n < $take; $n++) {
                $pairs[] = [$removedKey, $addedKey];
            }

            $left -= $take;
            $added[$addedKey] -= $take;
            if ($added[$addedKey] === 0) {
                unset($added[$addedKey]);
                array_shift($addedByGroup[$group]);
            }
        }

        if ($left > 0) {
            $removed[$removedKey] = $left;
        } else {
            unset($removed[$removedKey]);
        }
    }

    return $pairs;
};

/** Collapse whitespace and make a string safe inside a markdown inline-code span. */
$code = static fn(string $s): string => '`' . str_replace('`', "'", trim((string) preg_replace('/\s+/u', ' ', $s))) . '`';

/** Clip to $limit characters with an ellipsis. */
$clip = static fn(string $s, int $limit): string => mb_strlen($s) > $limit ? mb_substr($s, 0, $limit - 1) . '…' : $s;

/**
 * Old/new message ready to print. Short messages pass through; long ones are
 * cut to a window around their first and last difference so the changed part
 * (e.g. a malformed type at the end of a long message) is never clipped away.
 *
 * @return array{0: string, 1: string}
 */
$messagePair = static function (string $old, string $new) use ($clip): array {
    if (mb_strlen($old) <= MESSAGE_LIMIT && mb_strlen($new) <= MESSAGE_LIMIT) {
        return [$old, $new];
    }

    $a = mb_str_split($old);
    $b = mb_str_split($new);
    $max = min(count($a), count($b));
    $prefix = 0;
    while ($prefix < $max && $a[$prefix] === $b[$prefix]) {
        $prefix++;
    }

    $suffix = 0;
    while ($suffix < $max - $prefix && $a[count($a) - 1 - $suffix] === $b[count($b) - 1 - $suffix]) {
        $suffix++;
    }

    $start = max(0, $prefix - DIFF_CONTEXT);
    $cutTail = max(0, $suffix - DIFF_CONTEXT);
    $window = static fn(array $chars): string => ($start > 0 ? '…' : '')
        . implode('', array_slice($chars, $start, count($chars) - $start - $cutTail))
        . ($cutTail > 0 ? '…' : '');

    return [$clip($window($a), MESSAGE_LIMIT), $clip($window($b), MESSAGE_LIMIT)];
};

/**
 * @var list<array{
 *     app: string,
 *     added: int,
 *     removed: int,
 *     changed: list<array{type: string, loc: string, old: string, new: string}>,
 *     moved: list<array{type: string, from: string, to: string, message: string}>,
 *     net: int,
 *     movements: list<array{type: string, level: int|null, base: int, head: int, added: int, removed: int, changed: int, moved: int, delta: int}>,
 * }> $rows
 */
$rows = [];
/** @var list<string> $missing */
$missing = [];
/** @var array<string, array{base: bool, head: bool}> $usable  which sides left a parsable issues.json */
$usable = [];

foreach ($apps as $app) {
    $baseIssues = $loadJson($latest($outputDir, $app, $baseLabel, 'issues.json', $dateMarker));
    $headIssues = $loadJson($latest($outputDir, $app, $headLabel, 'issues.json', $dateMarker));

    $baseOk = is_array($baseIssues) && array_is_list($baseIssues);
    $headOk = is_array($headIssues) && array_is_list($headIssues);
    if (!$baseOk || !$headOk) {
        $usable[$app] = ['base' => $baseOk, 'head' => $headOk];
        $missing[] = $app;
        continue;
    }

    [$baseCounts, $baseTypes, $baseLevels] = $tally($baseIssues);
    [$headCounts, $headTypes, $headLevels] = $tally($headIssues);
    unset($baseIssues, $headIssues);

    // head is read after base so the post-PR level wins on cross-side disagreement.
    $typeLevel = $headLevels + $baseLevels;
    $net = array_sum($headTypes) - array_sum($baseTypes);

    // Multiset diff: a key's count change, not mere presence.
    $removed = [];
    foreach ($baseCounts as $key => $n) {
        if ($n > ($headCounts[$key] ?? 0)) {
            $removed[$key] = $n - ($headCounts[$key] ?? 0);
        }
    }

    $added = [];
    foreach ($headCounts as $key => $n) {
        if ($n > ($baseCounts[$key] ?? 0)) {
            $added[$key] = $n - ($baseCounts[$key] ?? 0);
        }
    }

    unset($baseCounts, $headCounts);

    // Key parts: 0 file, 1 line_from, 2 line_to, 3 type, 4 message, 5/6 columns.
    // A message change groups by (file, line_from, type) only: the end line of a
    // multi-line issue can shift together with its message.
    $sameLineMoves = $pairUp($removed, $added, [0, 1, 2, 3, 4]);
    $messageChanges = $pairUp($removed, $added, [0, 1, 3]);
    $relocations = $pairUp($removed, $added, [3, 4]);

    $changedByType = [];
    $changedList = [];
    foreach ($messageChanges as [$oldKey, $newKey]) {
        $o = explode("\x00", $oldKey);
        $changedByType[$o[3]] = ($changedByType[$o[3]] ?? 0) + 1;
        [$oldMsg, $newMsg] = $messagePair($o[4], explode("\x00", $newKey)[4]);
        $changedList[] = [
            'type' => $o[3],
            'loc' => $o[1] === $o[2] ? "{$o[0]}:{$o[1]}" : "{$o[0]}:{$o[1]}-{$o[2]}",
            'old' => $oldMsg,
            'new' => $newMsg,
        ];
    }

    $movedByType = [];
    $movedList = [];
    foreach ([...$sameLineMoves, ...$relocations] as [$oldKey, $newKey]) {
        $o = explode("\x00", $oldKey);
        $nw = explode("\x00", $newKey);
        $movedByType[$o[3]] = ($movedByType[$o[3]] ?? 0) + 1;
        $movedList[] = [
            'type' => $o[3],
            'from' => "{$o[0]}:{$o[1]}:{$o[5]}",
            'to' => "{$nw[0]}:{$nw[1]}:{$nw[5]}",
            'message' => $o[4],
        ];
    }

    $locOrder = static fn(array $x, array $y): int => [$x['loc'] ?? $x['from'], $x['type']] <=> [$y['loc'] ?? $y['from'], $y['type']];
    usort($changedList, $locOrder);
    usort($movedList, $locOrder);

    // Per-type counts of what is left after pairing: true +/-. Net totals alone
    // hide churn (a fixed issue replaced by a new one of the same type nets to
    // zero), so each type also reports its identity movements.
    $addedByType = [];
    foreach ($added as $key => $n) {
        $type = explode("\x00", $key)[3];
        $addedByType[$type] = ($addedByType[$type] ?? 0) + $n;
    }

    $removedByType = [];
    foreach ($removed as $key => $n) {
        $type = explode("\x00", $key)[3];
        $removedByType[$type] = ($removedByType[$type] ?? 0) + $n;
    }

    $movements = [];
    foreach (array_keys($baseTypes + $headTypes) as $type) {
        $a = $addedByType[$type] ?? 0;
        $r = $removedByType[$type] ?? 0;
        $c = $changedByType[$type] ?? 0;
        $m = $movedByType[$type] ?? 0;
        if ($a === 0 && $r === 0 && $c === 0 && $m === 0) {
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
            'moved' => $m,
            'delta' => ($headTypes[$type] ?? 0) - ($baseTypes[$type] ?? 0),
        ];
    }

    // Signed net delta ascending (biggest reductions first, regressions last);
    // tie-break by churn volume so pure churn sorts by size, then by type name.
    usort(
        $movements,
        /**
         * @param array{type: string, added: int, removed: int, changed: int, moved: int, delta: int} $x
         * @param array{type: string, added: int, removed: int, changed: int, moved: int, delta: int} $y
         */
        static fn(array $x, array $y): int => [$x['delta'], -($x['added'] + $x['removed'] + $x['changed'] + $x['moved']), $x['type']]
            <=> [$y['delta'], -($y['added'] + $y['removed'] + $y['changed'] + $y['moved']), $y['type']],
    );

    $rows[] = [
        'app' => $app,
        'added' => array_sum($added),
        'removed' => array_sum($removed),
        'changed' => $changedList,
        'moved' => $movedList,
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
/** @var list<string> $warnings */
$warnings = [];
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

    // A side that ran degraded/with different inputs makes the delta
    // untrustworthy even when issues.json parses, so say so at the top.
    foreach (['base' => $basePerf, 'head' => $headPerf] as $side => $perf) {
        $status = is_array($perf) ? ($perf['plugin_status'] ?? 'ok') : 'ok';
        if (is_string($status) && $status !== 'ok') {
            $warnings[] = "**{$app}**: Laravel plugin {$status} on {$side} — the issue delta for this app is not meaningful.";
        }
    }

    if (($basePerf['deps_diverged'] ?? false) === true || ($headPerf['deps_diverged'] ?? false) === true) {
        $warnings[] = "**{$app}**: app dependencies (other than the plugin) differ between base and head — the delta may come from dependencies, not this PR.";
    }

    if (is_array($basePerf) && is_array($headPerf)
        && isset($basePerf['threads'], $headPerf['threads']) && $basePerf['threads'] !== $headPerf['threads']) {
        $warnings[] = sprintf('**%s**: ran with different thread counts (base %s, head %s); Psalm output can depend on it.', $app, json_encode($basePerf['threads']), json_encode($headPerf['threads']));
    }

    $baseVersions = is_array($basePerf) ? ($basePerf['versions'] ?? null) : null;
    $headVersions = is_array($headPerf) ? ($headPerf['versions'] ?? null) : null;
    if (is_array($baseVersions) && is_array($headVersions)) {
        $diffs = [];
        foreach (array_keys($baseVersions + $headVersions) as $name) {
            $b = $baseVersions[$name] ?? null;
            $h = $headVersions[$name] ?? null;
            if ($b !== $h) {
                $diffs[] = sprintf('%s %s → %s', $name, is_scalar($b) ? (string) $b : '—', is_scalar($h) ? (string) $h : '—');
            }
        }

        if ($diffs !== []) {
            $warnings[] = "**{$app}**: versions differ between base and head (" . implode(', ', $diffs) . ') — the delta may reflect them, not this PR.';
        }
    }
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

if ($warnings !== []) {
    $out[] = '### Warnings';
    $out[] = '';
    foreach ($warnings as $warning) {
        $out[] = "> {$warning}";
        $out[] = '>';
    }

    array_pop($out);
    $out[] = '';
}

// --- Per-app delta table (changed apps only) --------------------------------
//
// Columns: + (added), − (removed), Changed (same place, new message), Moved
// (same issue, new place), Δ (net = head − base). Changed and moved pairs are
// neutral churn, so Δ = + − −. Only apps with any of the four appear.

$changed = array_values(array_filter(
    $rows,
    static fn(array $r): bool => $r['added'] + $r['removed'] + count($r['changed']) + count($r['moved']) > 0,
));

// "+a/-r" plus the changed/moved counts only when non-zero.
$churn = static fn(int $a, int $r, int $c, int $m): string => sprintf('+%d/-%d', $a, $r)
    . ($c > 0 ? sprintf(', %d changed', $c) : '')
    . ($m > 0 ? sprintf(', %d moved', $m) : '');

// Line range of the collapsible breakdown body inside $out, so an oversized
// report can shed it (and only it) to fit the comment limit.
$detailsFrom = null;
$detailsTo = null;

$out[] = '### Per-app delta — Issues';
$out[] = '';
if ($changed === []) {
    $out[] = 'No issue changes across the benchmarked apps.';
} else {
    $out[] = '| App | + | − | Changed | Moved | Δ |';
    $out[] = '|-----|----:|----:|----:|----:|-----:|';
    $tAdded = 0;
    $tRemoved = 0;
    $tChanged = 0;
    $tMoved = 0;
    $tNet = 0;
    foreach ($changed as $r) {
        $out[] = sprintf('| %s | %d | %d | %d | %d | %+d |', $r['app'], $r['added'], $r['removed'], count($r['changed']), count($r['moved']), $r['net']);
        $tAdded += $r['added'];
        $tRemoved += $r['removed'];
        $tChanged += count($r['changed']);
        $tMoved += count($r['moved']);
        $tNet += $r['net'];
    }

    $out[] = sprintf('| **Total** | **%d** | **%d** | **%d** | **%d** | **%+d** |', $tAdded, $tRemoved, $tChanged, $tMoved, $tNet);
    $out[] = '';
    $out[] = '_Changed = same place and type, new message. Moved = same type and message, new place. Both are excluded from + and −._';

    // Per-app issue-type movements, all under ONE collapsible block so the
    // comment stays compact regardless of how many apps changed.
    $out[] = '';
    $out[] = '<details><summary>Per-app issue-type breakdown</summary>';
    $detailsFrom = count($out);
    foreach ($changed as $r) {
        $out[] = '';
        $out[] = sprintf('#### %s (%s)', $r['app'], $churn($r['added'], $r['removed'], count($r['changed']), count($r['moved'])));
        $out[] = '';
        foreach ($r['movements'] as $m) {
            // Prefix the Psalm error level (e.g. "L3: ") so a reader can gauge
            // severity at a glance — lower levels are stricter/higher-signal.
            // Omitted only if the JSON carried no error_level for the type.
            $levelPrefix = $m['level'] !== null ? sprintf('L%d: ', $m['level']) : '';
            $out[] = sprintf(
                '- %s%s: %d -> %d (%s)',
                $levelPrefix,
                $m['type'],
                $m['base'],
                $m['head'],
                $churn($m['added'], $m['removed'], $m['changed'], $m['moved']),
            );
        }

        // Per-issue entries print file paths and diagnostic text, so they are
        // opt-in (--details): the default output is aggregate counts only.
        if ($showDetails && $r['changed'] !== []) {
            $out[] = '';
            $out[] = '**Message changed**';
            $out[] = '';
            foreach (array_slice($r['changed'], 0, $top) as $c) {
                $out[] = sprintf('- %s at `%s`', $c['type'], $c['loc']);
                $out[] = '  - old: ' . $code($c['old']);
                $out[] = '  - new: ' . $code($c['new']);
            }

            if (count($r['changed']) > $top) {
                $out[] = sprintf('- … %d more', count($r['changed']) - $top);
            }
        }

        if ($showDetails && $r['moved'] !== []) {
            $out[] = '';
            $out[] = '**Moved**';
            $out[] = '';
            foreach (array_slice($r['moved'], 0, $top) as $mv) {
                $out[] = sprintf('- %s `%s` → `%s`: %s', $mv['type'], $mv['from'], $mv['to'], $code($clip($mv['message'], MESSAGE_LIMIT)));
            }

            if (count($r['moved']) > $top) {
                $out[] = sprintf('- … %d more', count($r['moved']) - $top);
            }
        }
    }

    $detailsTo = count($out);
    $out[] = '';
    $out[] = '</details>';
}

// Apps that ran on both sides but produced no delta — listed here under Issues
// (with the issue results) rather than down by the perf tables, and kept
// separate from crashes so "nothing changed" is never read as "nothing ran".
$noChange = array_values(array_filter(
    $rows,
    static fn(array $r): bool => $r['added'] + $r['removed'] + count($r['changed']) + count($r['moved']) === 0,
));
if ($noChange !== []) {
    $names = array_map(static fn(array $r): string => $r['app'], $noChange);
    $out[] = '';
    $out[] = '> No change (ran on both sides, zero delta): ' . implode(', ', $names) . '.';
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
// whose coverage actually moved are listed, judged on the raw values: Psalm
// reports 4 decimals, and a 2-decimal rounding would hide a real regression
// (84.9520 -> 84.9512). A side with no perf.json (crash / not run) is skipped
// here since it already appears in the Crashed / Not-run buckets.
const COVERAGE_EPSILON = 1e-6;

$covLines = [];
foreach ($perfRows as $p) {
    if ($p['baseCov'] === null || $p['headCov'] === null) {
        continue; // not comparable — surfaced in its own bucket, not here
    }

    $delta = $p['headCov'] - $p['baseCov'];
    if (abs($delta) < COVERAGE_EPSILON) {
        continue; // coverage unchanged
    }

    $covLines[] = sprintf('| %s | %.4f | %.4f | %+.4f |', $p['app'], $p['baseCov'], $p['headCov'], $delta);
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
    $out[] = sprintf('| **Weighted Δ** | — | — | **%+.4f** |', $weightNum / $weightDen);
    $out[] = '';
    $out[] = '_Weighted Δ is weighted by base `wall_seconds` (proxy for codebase size)._';
}

// Split "no issues.json" apps into genuine crashes (a crash log exists on at
// least one side, shown with its error and the side) vs apps that never ran /
// left no artifact.
if ($missing !== []) {
    /** @var array<string, array{base: string|null, head: string|null}> $crashed */
    $crashed = [];
    /** @var list<string> $notRun */
    $notRun = [];
    foreach ($missing as $app) {
        $sides = ['base' => $crashExcerpt($app, $baseLabel), 'head' => $crashExcerpt($app, $headLabel)];
        if ($sides['base'] !== null || $sides['head'] !== null) {
            $crashed[$app] = $sides;
        } else {
            $notRun[] = $app;
        }
    }

    if ($crashed !== []) {
        $out[] = '';
        $out[] = '### Crashed';
        $out[] = '';
        $out[] = 'No usable report — Psalm crashed or analysis aborted. A crash on base (or both sides) is not caused by this PR; a head-only crash is.';
        $out[] = '';
        foreach ($crashed as $app => $sides) {
            if ($sides['base'] !== null && $sides['head'] !== null) {
                $out[] = $sides['base'] === $sides['head']
                    ? "- **{$app}** (base and head): `{$sides['base']}`"
                    : "- **{$app}**: base: `{$sides['base']}`; head: `{$sides['head']}`";
                continue;
            }

            $side = $sides['base'] !== null ? 'base' : 'head';
            $other = $side === 'base' ? 'head' : 'base';
            $note = $usable[$app][$other] ? '' : "; {$other} left no report either";
            $out[] = "- **{$app}** ({$side} only{$note}): `{$sides[$side]}`";
        }
    }

    if ($notRun !== []) {
        $out[] = '';
        $out[] = '> Not run (install failed or no artifact): ' . implode(', ', $notRun) . '.';
    }
}

// --- Size cap ------------------------------------------------------------------
//
// The breakdown is the only part that grows with the data (types x apps), so it
// is what gets cut; warnings, tables and crashes stay. If the rest alone is too
// big, fall back to a hard cut of the whole body.
$truncationNotice = '_Report truncated to fit the comment size limit; run `bash bin/ci/delta.sh <pr-branch>` locally for the full report._';
$length = static fn(array $lines): int => strlen(implode("\n", $lines)) + 1;

if ($length($out) > MAX_BODY_BYTES) {
    if ($detailsFrom !== null && $detailsTo !== null) {
        $head = array_slice($out, 0, $detailsFrom);
        $tail = [...array_slice($out, $detailsTo), '', $truncationNotice];
        $budget = MAX_BODY_BYTES - $length([...$head, ...$tail]);
        $kept = [];
        foreach (array_slice($out, $detailsFrom, $detailsTo - $detailsFrom) as $line) {
            $budget -= strlen($line) + 1;
            if ($budget < 0) {
                break;
            }

            $kept[] = $line;
        }

        $out = [...$head, ...$kept, ...$tail];
    }

    if ($length($out) > MAX_BODY_BYTES) {
        // Byte cut that never splits a multibyte UTF-8 sequence.
        $body = mb_strcut(implode("\n", $out), 0, MAX_BODY_BYTES - strlen($truncationNotice) - 4, 'UTF-8');
        $out = [$body, '', $truncationNotice];
    }
}

echo implode("\n", $out) . "\n";
exit(0);
