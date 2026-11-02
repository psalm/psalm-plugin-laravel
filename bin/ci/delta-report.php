<?php

declare(strict_types=1);

/**
 * Build a base-vs-head issue-delta report (markdown) in the layout of vimeo/psalm's
 * perf report, from per-app Psalm JSON.
 *
 * Reads issues.json + perf.json files produced by delta-app.sh out of an
 * OUTPUT_DIR, matches base/head pairs per app, and prints:
 *
 *   * a heading, "<icon> Issues: <increased|decreased|unchanged> · Performance:
 *     <regressed|improved|unchanged>"
 *   * one table row per selected app (crashed or not run apps included): wall time,
 *     peak memory, head issue count with +added/−removed/changed badges, weighted
 *     issues, type coverage; a Total row over the apps compared on both sides
 *   * a legend, then one collapsible block per app with anything beyond the table
 *     (weighted breakdown by error level, issue-type table, order-dependent
 *     exclusions; with --details also the changed, added and removed entries and
 *     crash text)
 *   * a footer with the head and base commits and an optional workflow run link
 *
 * Icons: ✅ both sides produced a report, ❌ head has none while base does (the PR
 * crashed), 💥 not comparable (base crashed, both crashed, or not run).
 *
 * File layout (written by delta-app.sh):
 *   <output_dir>/<app>/<app>-<label>--issues.json
 *   <output_dir>/<app>/<app>-<label>--perf.json   (wall_seconds, type_coverage_pct, peak_rss_mb, ...)
 *   <output_dir>/<app>/<app>-<label>--crash.log   (only when the side crashed)
 *
 * Issue identity is the multiset of (file_name, line_from, line_to,
 * column_from, column_to, type, message), so a PR fixing 50 issues and
 * introducing 50 new ones reports +50/-50 instead of ΔNet=0. Leftover
 * removed/added entries on the same file, line_from and type with a different
 * message are paired as "changed" and leave the +/- totals.
 *
 * Usage:
 *   php delta-report.php <output_dir> <base_label> <head_label> \
 *       --apps=monica,pixelfed,coolify \
 *       [--base-ref=] [--head-ref=] [--base-sha=] [--head-sha=] [--explicit-base] \
 *       [--details] [--selection='default + octane'] [--flags='--find-dead-code'] \
 *       [--run-url=https://github.com/<owner>/<repo>/actions/runs/<id>]
 *
 * --details prints the changed, added and removed entries and crash text. They
 * carry file paths and issue messages, so they are OFF by default (safe for a
 * private app). The type table and all figures appear either way.
 *
 * --selection is the resolved /psalm-delta selector label (bin/ci/delta-select-apps.php),
 * printed under the heading with the app count. --flags is the run-wide
 * `psalm-laravel analyze` flags both sides ran with, printed below it.
 *
 * The footer labels base "(merge-base)" unless --explicit-base says it was chosen by hand.
 * --run-url adds the workflow run link to the footer.
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
    fwrite(STDERR, "Usage: php delta-report.php <output_dir> <base_label> <head_label> --apps=a,b,c [--base-ref=] [--head-ref=] [--base-sha=] [--head-sha=] [--explicit-base] [--details] [--selection=label] [--flags='--find-dead-code'] [--run-url=url]\n");
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
$details = isset($options['details']);
$selection = $options['selection'] ?? '';
$flags = $options['flags'] ?? '';
$runUrl = $options['run-url'] ?? '';
$explicitBase = isset($options['explicit-base']);

// Issue types whose presence depends on the order Psalm merges parallel workers
// (first-merge-wins CodeUseGraph::$mutation_info after the thread pool join),
// not on the code: excluded from +/−/Changed/Δ.
const ORDER_DEPENDENT_TYPES = ['MissingPureAnnotation'];

// --details entry lists: at most DETAILS_CAP entries per list per app, and no
// further app's entries once the report passes DETAILS_BUDGET bytes, so a
// churny all-apps run stays under GitHub's 65,536-char comment limit.
const DETAILS_CAP = 10;
const DETAILS_BUDGET = 40_000;

// A report over this many bytes is cut short: GitHub caps a comment at 65,536
// characters and the workflow appends a hint after it.
const REPORT_MAX_BYTES = 60_000;

// Wall time on shared CI runners is noisy (process scheduling, IO): identical
// runs vary by a second or two, so a raw Δ is mostly jitter, not PR impact. A
// wall badge is colored only when the move clears BOTH floors.
const WALL_NOISE_SECONDS = 3.0; // below this a Δ is treated as runner jitter
const WALL_NOISE_PCT = 10.0;

// Peak RSS of identical runs differs by well under 1 % (corcel: 750.8 to 751.6 MB
// over four runs), so a 5 % move is real, unlike wall time.
const MEMORY_THRESHOLD_PCT = 5.0;

// Psalm's parallel analysis is nondeterministic: which files share a worker
// changes how many non-mixed expressions get counted, so identical code and
// vendor measured pixelfed at 84.9672-84.9693% across 4-thread runs (serial
// runs repeat exactly, but cost ~2x wall time). Moves under this are that
// jitter, so they are dropped on the RAW Δ and the rest is shown at 2
// decimals. Gating on the raw Δ, not on rounded values, keeps jitter that
// straddles a rounding boundary from surfacing as a phantom ±0.01.
const COVERAGE_NOISE_FLOOR = 0.005; // percentage points; also the smallest move visible at 2 decimals

const ICON_ORDER = ['❌' => 0, '💥' => 1, '✅' => 2];
const LEVEL_BUCKETS = ['L1', 'L2', 'L3', 'L4', 'L5', 'L6', 'L7', 'L8', 'always', 'feature'];

const TABLE_LEGEND = "<details><summary>How to read this table</summary>\n\n"
    . "- **Wall**: head seconds. A badge is colored only for a move of at least 3 s and 10 %, "
    . "as shared runners are noisy: slower is red, faster is green, anything smaller is gray.\n"
    . "- **Memory**: peak RSS of the largest process in Psalm's process tree, workers included. "
    . "Red above +5 %, green below −5 %, gray between. A dash means a side has no figure.\n"
    . '- **Issues**: head count, then $\color{red}{\textsf{+added}}$ $\color{green}{\textsf{-removed}}$ '
    . 'against base, and $\color{orange}{\textsf{N changed}}$ for issues at the same place and type with a new message '
    . "(not counted in added or removed).\n"
    . '- **Weighted**: issues weighted by error level (levels 1–8 as is, always-errors 10, feature-specific 0), '
    . "order-dependent types excluded. A rise is not necessarily a regression.\n"
    . "- **Coverage**: inferred type coverage. A badge appears only for a move of at least 0.005 points, "
    . "smaller moves are parallel-analysis jitter.\n"
    . "- **Total**: sum over the apps compared on both sides (coverage is not summed). Badge thresholds and the heading words "
    . "apply per app, not to the total, so the Total row can be colored differently from the heading.\n"
    . '- **Colors**: $\color{red}{\textsf{red}}$ worse or more issues, '
    . '$\color{green}{\textsf{green}}$ better or fewer issues, '
    . '$\color{gray}{\textsf{gray}}$ noise.' . "\n"
    . "- **Icons**: ✅ both sides produced a report · ❌ head produced none while base did · "
    . "💥 not comparable (base crashed, both crashed, or not run). A crash on both sides is not caused by this PR.\n"
    . '- **Performance** (heading): *regressed* if a head run crashed or a wall or memory badge is red, '
    . "else *improved* if one is green, else *unchanged*. **Issues** (heading): direction of the total weighted change. "
    . "Informational, never fails the PR.\n\n"
    . '</details>';

$sideFile = static fn(string $app, string $label, string $suffix): string => "{$outputDir}/{$app}/{$app}-{$label}--{$suffix}";

/**
 * @return array<array-key, mixed>|null  decoded JSON array, or null on any failure
 */
$loadJson = static function (string $path): ?array {
    if (!is_file($path)) {
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
$crashExcerpt = static function (string $app) use ($sideFile, $baseLabel, $headLabel): array {
    $sides = [];
    foreach (['base' => $baseLabel, 'head' => $headLabel] as $side => $label) {
        $path = $sideFile($app, $label, 'crash.log');
        if (!is_file($path)) {
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
        // Psalm's file_name is relative to the psalm.xml dir, so it reads well in --details.
        (string) ($i['file_name'] ?? ''),
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
        // The JSON is untrusted: only Psalm's real levels (-2, -1, 1..8) count, anything else has no level and weight 0.
        $level = isset($i['error_level']) && is_numeric($i['error_level']) ? $i['error_level'] + 0 : null;
        if (in_array($level, [-2, -1, 1, 2, 3, 4, 5, 6, 7, 8], true)) {
            $levels[$type] = $level;
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

/**
 * @var array<string, array{
 *     app: string,
 *     added: int,
 *     removed: int,
 *     changed: list<array{type: string, loc: string, old: string, new: string}>,
 *     addedList: list<array{type: string, loc: string, message: string}>,
 *     removedList: list<array{type: string, loc: string, message: string}>,
 *     headCount: int,
 *     baseBuckets: array<string, int>,
 *     headBuckets: array<string, int>,
 *     weight: array{int, int},
 *     movements: list<array{type: string, level: int|null, base: int, head: int, added: int, removed: int, changed: int, delta: int}>,
 *     volatile: list<string>,
 * }> $rows
 */
$rows = [];
/** @var array<string, array{bool, bool}> $missing app => [base has a report, head has a report] */
$missing = [];

foreach ($apps as $app) {
    $baseIssues = $loadJson($sideFile($app, $baseLabel, 'issues.json'));
    $headIssues = $loadJson($sideFile($app, $headLabel, 'issues.json'));

    if (!is_array($baseIssues) || !array_is_list($baseIssues)
        || !is_array($headIssues) || !array_is_list($headIssues)) {
        $missing[$app] = [is_array($baseIssues) && array_is_list($baseIssues), is_array($headIssues) && array_is_list($headIssues)];
        continue;
    }

    [$baseKeys, $baseTypes, $baseLevels, $baseOrder] = $tally($baseIssues);
    [$headKeys, $headTypes, $headLevels, $headOrder] = $tally($headIssues);
    $baseBuckets = bucketCounts($baseTypes, $baseLevels);
    $headBuckets = bucketCounts($headTypes, $headLevels);
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
        $type = (string) $type; // a numeric issue type became an int array key
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

    $rows[$app] = [
        'app' => $app,
        'added' => array_sum($added),
        'removed' => array_sum($removed),
        'changed' => $changed,
        'addedList' => $details ? $entries($added) : [],
        'removedList' => $details ? $entries($removed) : [],
        'headCount' => array_sum($headTypes),
        'baseBuckets' => $baseBuckets,
        'headBuckets' => $headBuckets,
        'weight' => [weightedSum($baseBuckets), weightedSum($headBuckets)],
        'movements' => $movements,
        'volatile' => $volatile,
    ];
}

// --- Report ------------------------------------------------------------------

/** Bucket of a Psalm error level: L1…L8, `always` (-1, always an error) or `feature` (-2, feature-specific). */
function levelBucket(int $level): string
{
    return match ($level) {
        -1 => 'always',
        -2 => 'feature',
        default => "L{$level}",
    };
}

/**
 * Issue counts per level bucket; a type without a level is not bucketed.
 *
 * @param array<string, int> $types
 * @param array<string, int> $levels
 * @return array<string, int>
 */
function bucketCounts(array $types, array $levels): array
{
    $counts = [];
    foreach ($types as $type => $n) {
        if (isset($levels[$type])) {
            $bucket = levelBucket($levels[$type]);
            $counts[$bucket] = ($counts[$bucket] ?? 0) + $n;
        }
    }

    return $counts;
}

/**
 * Weighted issue count: its level for L1…L8, 10 for `always`, 0 for `feature`.
 *
 * @param array<string, int> $counts
 */
function weightedSum(array $counts): int
{
    $sum = 0;
    foreach ($counts as $bucket => $n) {
        $sum += $n * match ($bucket) {
            'always' => 10,
            'feature' => 0,
            default => (int) substr($bucket, 1),
        };
    }

    return $sum;
}

/** Signed relative change; 'n/a' for null, '<0.01 %' when a non-zero change rounds to zero. */
function fmtPct(?float $pct, int $precision = 1): string
{
    if ($pct === null) {
        return 'n/a';
    }

    $unit = 10 ** -$precision;

    return abs($pct) > 0.0 && abs($pct) < $unit / 2
        ? sprintf('<%s %%', number_format($unit, $precision))
        : str_replace('-', '−', ($pct < 0 ? '' : '+') . number_format($pct, $precision, '.', '') . ' %');
}

/** Integer with a NBSP thousands separator. */
function fmtInt(int $n): string
{
    return number_format($n, 0, '', "\u{A0}");
}

function fmtSigned(int $n): string
{
    return ($n < 0 ? '−' : '+') . fmtInt(abs($n));
}

/** Single-line text safe in a table cell or <summary>. */
function tableCell(string $text): string
{
    return str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], $text);
}

/** Issue types come from fork-controlled JSON: only a Psalm type name is rendered. */
function issueType(string $type): string
{
    return preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $type) === 1 ? $type : '(invalid type)';
}

/** Markdown inline-code span of a message: one line, clipped. */
function inlineCode(string $s): string
{
    return '`' . str_replace('`', "'", mb_strimwidth((string) preg_replace('/\s+/u', ' ', $s), 0, 300, '…')) . '`';
}

/** GitHub math text in a color. Markdown unescapes `\\%` to LaTeX's `\%`; a bare `%` would start a LaTeX comment. */
function colored(string $text, string $color): string
{
    return '$\color{' . $color . '}{\textsf{' . str_replace('%', '\\\\%', $text) . '}}$';
}

function pctDelta(float $base, float $head): ?float
{
    return $base > 0.0 ? ($head - $base) / $base * 100 : null;
}

/** Gray inside the runner-jitter floors, red when slower and green when faster beyond both. */
function wallColor(float $base, float $head): string
{
    $pct = pctDelta($base, $head);
    if ($pct === null || abs($head - $base) < WALL_NOISE_SECONDS || abs($pct) < WALL_NOISE_PCT) {
        return 'gray';
    }

    return $head > $base ? 'red' : 'green';
}

function memoryColor(float $base, float $head): string
{
    $pct = pctDelta($base, $head) ?? 0.0;

    return $pct > MEMORY_THRESHOLD_PCT ? 'red' : ($pct < -MEMORY_THRESHOLD_PCT ? 'green' : 'gray');
}

/** " +7.7%" in the given color; '' when it rounds to zero. */
function pctBadge(?float $pct, string $color): string
{
    return $pct === null || abs($pct) < 0.05 ? '' : ' ' . colored(sprintf('%+.1f%%', $pct), $color);
}

/** " +14" in red, " -36" in green; '' for zero. */
function countBadge(int $delta): string
{
    return $delta === 0
        ? ''
        : ' ' . colored(($delta > 0 ? '+' : '-') . fmtInt(abs($delta)), $delta > 0 ? 'red' : 'green');
}

/** " +9 -3", a side that did not move in gray; '' when nothing was added or removed. */
function addedRemovedBadge(int $added, int $removed): string
{
    return $added === 0 && $removed === 0
        ? ''
        : ' ' . colored("+{$added}", $added > 0 ? 'red' : 'gray') . ' '
            . colored("-{$removed}", $removed > 0 ? 'green' : 'gray');
}

/**
 * Head figure plus its badge; the figure alone when the app is not comparable or base has none; "–" without a head figure.
 *
 * @param array<string, mixed> $p
 */
function perfCell(array $p, string $metric): string
{
    [$base, $head] = $p[$metric];
    if ($head === null) {
        return '–';
    }

    $cell = match ($metric) {
        'mem' => fmtInt((int) round($head)),
        'cov' => sprintf('%.2f', $head),
        default => sprintf('%.1f', $head),
    };
    if ($p['row'] === null || $base === null) {
        return $cell;
    }

    return $cell . match ($metric) {
        'mem' => pctBadge(pctDelta($base, $head), memoryColor($base, $head)),
        'cov' => abs($head - $base) < COVERAGE_NOISE_FLOOR
            ? ''
            : ' ' . colored(sprintf('%+.2f', $head - $base), $head > $base ? 'green' : 'red'),
        default => pctBadge(pctDelta($base, $head), wallColor($base, $head)),
    };
}

/** @param array<string, mixed> $p */
function issuesCell(array $p): string
{
    $r = $p['row'];
    if ($r === null) {
        return '–';
    }

    return fmtInt($r['headCount']) . addedRemovedBadge($r['added'], $r['removed'])
        . ($r['changed'] === [] ? '' : ' ' . colored(count($r['changed']) . ' changed', 'orange'));
}

/** @param array<string, mixed> $p */
function weightedCell(array $p): string
{
    $r = $p['row'];

    return $r === null ? '–' : fmtInt($r['weight'][1]) . countBadge($r['weight'][1] - $r['weight'][0]);
}

/**
 * Sums over the apps compared on both sides; wall and memory only over apps with both figures. Null when none was compared.
 *
 * @param list<array<string, mixed>> $projects
 */
function totalRow(array $projects): ?string
{
    $sum = ['wall' => [0.0, 0.0, 0], 'mem' => [0.0, 0.0, 0]];
    $head = $added = $removed = $changed = 0;
    $weight = [0, 0];
    $compared = 0;
    foreach ($projects as $p) {
        if ($p['row'] === null) {
            continue;
        }

        $compared++;
        foreach (['wall', 'mem'] as $metric) {
            [$b, $h] = $p[$metric];
            if ($b !== null && $h !== null) {
                $sum[$metric] = [$sum[$metric][0] + $b, $sum[$metric][1] + $h, $sum[$metric][2] + 1];
            }
        }

        $head += $p['row']['headCount'];
        $added += $p['row']['added'];
        $removed += $p['row']['removed'];
        $changed += count($p['row']['changed']);
        $weight = [$weight[0] + $p['row']['weight'][0], $weight[1] + $p['row']['weight'][1]];
    }

    if ($compared === 0) {
        return null;
    }

    [$wallBase, $wallHead, $wallN] = $sum['wall'];
    [$memBase, $memHead, $memN] = $sum['mem'];

    return sprintf(
        "| **Total** | %s | %s | %s | %s | – |\n",
        $wallN === 0 ? '–' : sprintf('%.1f', $wallHead) . pctBadge(pctDelta($wallBase, $wallHead), wallColor($wallBase, $wallHead)),
        $memN === 0 ? '–' : fmtInt((int) round($memHead)) . pctBadge(pctDelta($memBase, $memHead), memoryColor($memBase, $memHead)),
        fmtInt($head) . addedRemovedBadge($added, $removed)
            . ($changed === 0 ? '' : ' ' . colored("{$changed} changed", 'orange')),
        fmtInt($weight[1]) . countBadge($weight[1] - $weight[0]),
    );
}

/**
 * Red or green wall and memory badges of a compared app.
 *
 * @param array<string, mixed> $p
 * @return list<string>
 */
function perfColors(array $p): array
{
    $colors = [];
    if ($p['row'] !== null) {
        foreach (['wall' => wallColor(...), 'mem' => memoryColor(...)] as $metric => $color) {
            [$base, $head] = $p[$metric];
            if ($base !== null && $head !== null) {
                $colors[] = $color($base, $head);
            }
        }
    }

    return $colors;
}

/**
 * Regressed on a head-only crash or a red badge, else improved on a green one, else unchanged.
 *
 * @param list<array<string, mixed>> $projects
 */
function performanceWord(array $projects): string
{
    $compared = $improved = false;
    foreach ($projects as $p) {
        if ($p['icon'] === '❌') {
            return 'regressed';
        }

        $compared = $compared || $p['row'] !== null;
        $colors = perfColors($p);
        if (in_array('red', $colors, true)) {
            return 'regressed';
        }

        $improved = $improved || in_array('green', $colors, true);
    }

    return $improved ? 'improved' : ($compared ? 'unchanged' : 'not compared');
}

/**
 * Direction of the total weighted change over the compared apps.
 *
 * @param list<array<string, mixed>> $projects
 */
function issuesWord(array $projects): string
{
    $compared = array_filter($projects, static fn(array $p): bool => $p['row'] !== null);
    if ($compared === []) {
        return 'not compared';
    }

    $sum = array_sum(array_map(static fn(array $p): int => $p['row']['weight'][1] - $p['row']['weight'][0], $compared));

    return $sum > 0 ? 'increased' : ($sum < 0 ? 'decreased' : 'unchanged');
}

/**
 * Non-zero per-level count deltas, e.g. "L2 +4 · always +1".
 *
 * @param array<string, mixed> $r
 */
function levelDeltas(array $r): string
{
    $deltas = [];
    foreach (LEVEL_BUCKETS as $bucket) {
        $delta = ($r['headBuckets'][$bucket] ?? 0) - ($r['baseBuckets'][$bucket] ?? 0);
        if ($delta !== 0) {
            $deltas[] = "{$bucket} " . fmtSigned($delta);
        }
    }

    return implode(' · ', $deltas);
}

/**
 * "Wall 41.0 → 41.2 s (+0.5 %) · Memory …"; a figure appears only when both sides have it.
 *
 * @param array<string, mixed> $p
 */
function figuresLine(array $p): string
{
    $parts = [];
    foreach (['wall' => ['Wall', 1, 's'], 'mem' => ['Memory', 0, 'MB'], 'cov' => ['Coverage', 2, '%']] as $metric => [$label, $decimals, $unit]) {
        [$base, $head] = $p[$metric];
        if ($base !== null && $head !== null) {
            $parts[] = sprintf(
                '**%s** %s → %s %s%s',
                $label,
                number_format($base, $decimals, '.', "\u{A0}"),
                number_format($head, $decimals, '.', "\u{A0}"),
                $unit,
                $metric === 'cov' ? '' : ' (' . fmtPct(pctDelta($base, $head)) . ')',
            );
        }
    }

    return implode(' · ', $parts);
}

/**
 * Details body: figures, weighted breakdown, issue-type table, entry lists, crash text.
 * "" when there is nothing beyond the table. Entry lists and crash text are --details only.
 *
 * @param array<string, mixed> $p
 */
function detailsBody(array $p, bool $details, bool $entries): string
{
    $parts = [];
    $r = $p['row'];
    if ($r !== null) {
        if (($levels = levelDeltas($r)) !== '') {
            [$base, $head] = $r['weight'];
            $change = $head === $base
                ? '='
                : fmtSigned($head - $base) . ($base === 0 ? '' : ', ' . fmtPct(pctDelta((float) $base, (float) $head), 2));
            $parts[] = sprintf('**Weighted** %s → %s (%s): %s', fmtInt($base), fmtInt($head), $change, $levels);
        }

        if ($r['movements'] !== []) {
            $table = ['| Issue type | Level | base → head | added | removed | changed |', '|:--|:--|--:|--:|--:|--:|'];
            foreach ($r['movements'] as $m) {
                $table[] = sprintf(
                    '| %s | %s | %s → %s | %d | %d | %d |',
                    tableCell(issueType($m['type'])),
                    $m['level'] === null ? '–' : levelBucket($m['level']),
                    fmtInt($m['base']),
                    fmtInt($m['head']),
                    $m['added'],
                    $m['removed'],
                    $m['changed'],
                );
            }

            $parts[] = implode("\n", $table);
        }

        $lists = [
            'Added' => [$r['added'], array_map(static fn(array $e): string => inlineCode("{$e['loc']} " . issueType($e['type']) . ": {$e['message']}"), $r['addedList'])],
            'Removed' => [$r['removed'], array_map(static fn(array $e): string => inlineCode("{$e['loc']} " . issueType($e['type']) . ": {$e['message']}"), $r['removedList'])],
            'Changed' => [count($r['changed']), array_map(static fn(array $c): string => inlineCode("{$c['loc']} " . issueType($c['type']) . ": {$c['old']}") . ' → ' . inlineCode($c['new']), $r['changed'])],
        ];
        foreach ($lists as $label => [$total, $items]) {
            if ($total === 0 || !$details) {
                continue;
            }

            if (!$entries) {
                $parts[] = "**{$label}** ({$total}): _entries omitted (comment size limit)_";
                continue;
            }

            $lines = array_map(static fn(string $item): string => "- {$item}", array_slice($items, 0, DETAILS_CAP));
            if ($total > DETAILS_CAP) {
                $lines[] = sprintf('- … and %d more', $total - DETAILS_CAP);
            }

            $parts[] = "**{$label}** ({$total})\n\n" . implode("\n", $lines);
        }

        if ($r['volatile'] !== []) {
            $parts[] = '> order-dependent, excluded: ' . implode(', ', $r['volatile']);
        }
    }

    // Exception text can name private classes or hosts: opt-in only.
    if ($details) {
        foreach ($p['crash'] as $side => $excerpt) {
            $parts[] = "**{$side} crash**: `{$excerpt}`";
        }
    }

    if ($parts === []) {
        return '';
    }

    if ($r !== null && ($figures = figuresLine($p)) !== '') {
        array_unshift($parts, $figures);
    }

    return implode("\n\n", $parts);
}

/**
 * "issues +12 −3, 2 changed", plus the crash note for apps without a comparison.
 *
 * @param array<string, mixed> $p
 */
function detailsTitle(array $p): string
{
    $title = $p['note'] === '' ? [] : [$p['note']];
    $r = $p['row'];
    if ($r !== null) {
        $issues = $r['added'] > 0 || $r['removed'] > 0 ? ["issues +{$r['added']} −{$r['removed']}"] : [];
        if ($r['changed'] !== []) {
            $issues[] = count($r['changed']) . ' changed';
        }

        if ($issues !== []) {
            $title[] = implode(', ', $issues);
        }

        if ($r['volatile'] !== []) {
            $title[] = 'order-dependent types excluded';
        }
    }

    return tableCell(implode('; ', $title));
}

/**
 * One collapsible block per app with anything beyond the table; a note alone becomes a plain line.
 *
 * @param array<string, mixed> $p
 */
function detailsBlock(array $p, bool $details, bool $entries): string
{
    $title = detailsTitle($p);
    $body = detailsBody($p, $details, $entries);
    if ($title === '' && $body === '') {
        return '';
    }

    $summary = "{$p['icon']} " . tableCell($p['app']) . ($title === '' ? '' : ": {$title}");

    return $body === ''
        ? "{$summary}\n\n"
        : "<details><summary>{$summary}</summary>\n\n{$body}\n\n</details>\n\n";
}

$perfNum = static function (?array $perf, string $key): ?float {
    return is_array($perf) && isset($perf[$key]) && is_numeric($perf[$key])
        ? (float) $perf[$key]
        : null;
};

// Every selected app gets a row: perf figures are collected for apps without an
// issue delta too (a PR can shift time, memory or coverage without moving any
// issue). A side that crashed left no perf.json, so its figures are null.
/** @var list<array<string, mixed>> $projects */
$projects = [];
foreach ($apps as $app) {
    $basePerf = $loadJson($sideFile($app, $baseLabel, 'perf.json'));
    $headPerf = $loadJson($sideFile($app, $headLabel, 'perf.json'));
    $row = $rows[$app] ?? null;
    $crash = [];
    $note = '';
    $icon = '✅';
    if ($row === null) {
        [$baseHasReport, $headHasReport] = $missing[$app];
        $crash = $crashExcerpt($app);
        // Head-only loss is the PR's doing; anything else is not comparable.
        $icon = $baseHasReport && !$headHasReport ? '❌' : '💥';
        $note = match (true) {
            $icon === '❌' => isset($crash['head']) ? 'head crashed' : 'head has no report',
            count($crash) === 2 => 'both crashed',
            isset($crash['base']) => 'base crashed',
            isset($crash['head']) => 'head crashed, no base report',
            default => 'not run',
        };
    }

    $projects[] = [
        'app' => $app,
        'icon' => $icon,
        'note' => $note,
        'row' => $row,
        'crash' => $crash,
        'wall' => [$perfNum($basePerf, 'wall_seconds'), $perfNum($headPerf, 'wall_seconds')],
        'mem' => [$perfNum($basePerf, 'peak_rss_mb'), $perfNum($headPerf, 'peak_rss_mb')],
        'cov' => [$perfNum($basePerf, 'type_coverage_pct'), $perfNum($headPerf, 'type_coverage_pct')],
    ];
}

$icons = array_column($projects, 'icon');
$headingIcon = in_array('❌', $icons, true) ? '❌' : (in_array('💥', $icons, true) ? '⚠️' : '✅');
// Stable: selection order within a group.
usort($projects, static fn(array $a, array $b): int => ICON_ORDER[$a['icon']] <=> ICON_ORDER[$b['icon']]);

$out = sprintf("## %s Issues: %s · Performance: %s\n\n", $headingIcon, issuesWord($projects), performanceWord($projects));
if ($selection !== '') {
    $out .= sprintf("Apps: %s → %d\n\n", $selection, count($apps));
}

if ($flags !== '') {
    $out .= sprintf("Flags (both sides): `%s`\n\n", $flags);
}

$out .= "| App | Wall (s) | Memory (MB) | Issues | Weighted | Coverage (%) |\n|:--|--:|--:|--:|--:|--:|\n";
foreach ($projects as $p) {
    $out .= sprintf(
        "| %s %s | %s | %s | %s | %s | %s |\n",
        $p['icon'],
        tableCell($p['app']),
        perfCell($p, 'wall'),
        perfCell($p, 'mem'),
        issuesCell($p),
        weightedCell($p),
        perfCell($p, 'cov'),
    );
}

$out .= (totalRow($projects) ?? '') . "\n" . TABLE_LEGEND . "\n\n";

$shaOf = static fn(string $sha, string $ref, string $label): string => $sha !== '' ? substr($sha, 0, 12) : ($ref !== '' ? $ref : $label);
$footer = sprintf(
    "<sub>head %s · base %s%s%s</sub>\n",
    $shaOf($headSha, $headRef, $headLabel),
    $shaOf($baseSha, $baseRef, $baseLabel),
    $explicitBase ? '' : ' (merge-base)',
    $runUrl === '' ? '' : " · [workflow run]({$runUrl})",
);

// Blocks in table order while they fit: entry lists stop at DETAILS_BUDGET, a
// block stops at REPORT_MAX_BYTES. The workflow appends a short hint after this.
$used = strlen($out) + strlen($footer);
$blocks = '';
$omitted = 0;
foreach ($projects as $p) {
    $block = detailsBlock($p, $details, $used + strlen($blocks) <= DETAILS_BUDGET);
    if ($used + strlen($blocks) + strlen($block) > REPORT_MAX_BYTES) {
        $block = detailsBlock($p, $details, false);
    }

    if ($used + strlen($blocks) + strlen($block) > REPORT_MAX_BYTES) {
        $omitted++;
        continue;
    }

    $blocks .= $block;
}

if ($omitted > 0) {
    $blocks .= "{$omitted} detail block(s) omitted for size.\n\n";
}

echo $out . $blocks . $footer;
exit(0);
