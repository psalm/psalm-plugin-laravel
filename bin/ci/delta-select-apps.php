<?php

declare(strict_types=1);

/**
 * Resolve a `/psalm-delta` selector to the apps to benchmark.
 *
 * Usage:
 *   yq -o=json bin/ci/test-apps.yml | COMMENT_BODY='/psalm-delta octane' php delta-select-apps.php
 *   yq -o=json bin/ci/test-apps.yml | php delta-select-apps.php 'octane,vito'   # no /psalm-delta prefix
 *
 * Grammar (first line only, case-insensitive, tokens split on whitespace/commas):
 *   /psalm-delta            the `default` group
 *   /psalm-delta <tok>...   `default` plus each token: a group tag or an app name
 *                           (a group wins over an app of the same name)
 *   /psalm-delta all        every app
 *   /psalm-delta help       reply with groups and syntax, start no run (`help`
 *                           with other tokens is an error)
 *   /psalm-delta ... --flag a token starting with `-` is a flag for
 *                           `psalm-laravel analyze` on both sides; it must be
 *                           declared verbatim under `flags:` in the registry
 *
 * Prints one JSON object: {status: run|help|error|ignore, apps_csv, label,
 * flags, matrix: {include: [...]}, reply}. The comment body is untrusted input
 * from a privileged workflow, so every output except `reply` is built only from
 * registry values, and `reply` echoes a user token only when it is plain
 * [a-z0-9_-] (a flag: the same behind a leading `-`/`--`, plus `.` and `=`).
 * Exit 2 = malformed registry.
 */

$fail = static function (string $message): never {
    fwrite(STDERR, "delta-select-apps: {$message}\n");
    exit(2);
};

$registry = json_decode((string) stream_get_contents(STDIN), true);
if (!is_array($registry) || !is_array($registry['groups'] ?? null) || !is_array($registry['apps'] ?? null)) {
    $fail('stdin must be the registry as JSON with `groups` and `apps` maps');
}

$reserved = ['all', 'help'];
$safe = '/^[a-z0-9][a-z0-9_-]{0,39}$/';

/** @var array<string, string> $groups */
$groups = [];
foreach ($registry['groups'] as $tag => $description) {
    if (!is_string($tag) || preg_match($safe, $tag) !== 1 || in_array($tag, $reserved, true)) {
        $fail('invalid group tag: ' . var_export($tag, true));
    }
    $groups[$tag] = (string) $description;
}
if (!isset($groups['default'])) {
    $fail('the `default` group must be declared');
}

// Flags pass to `psalm-laravel analyze` on both sides of every selected app. The workflow
// runs PR code with them, so only exact declared strings get through.
/** @var array<string, string> $flags flag => description */
$flags = [];
foreach ((array) ($registry['flags'] ?? []) as $flag => $description) {
    if (!is_string($flag) || preg_match('/^--[a-z0-9][a-z0-9-]{0,39}(=[a-z0-9._-]{1,40})?$/', $flag) !== 1) {
        $fail('invalid flag: ' . var_export($flag, true));
    }
    $flags[$flag] = (string) $description;
}

/** @var array<string, array<string, mixed>> $apps name => registry entry */
$apps = [];
$members = array_fill_keys(array_keys($groups), []);
$ungrouped = [];
foreach ($registry['apps'] as $app) {
    $name = is_array($app) ? ($app['name'] ?? null) : null;
    if (!is_string($name) || preg_match($safe, $name) !== 1 || in_array($name, $reserved, true) || isset($apps[$name])) {
        $fail('invalid or duplicate app name: ' . var_export($name, true));
    }
    $tags = (array) ($app['groups'] ?? []);
    foreach ($tags as $tag) {
        if (!is_string($tag) || !isset($groups[$tag])) {
            $fail("app {$name} has undeclared group: " . var_export($tag, true));
        }
        $members[$tag][] = $name;
    }
    if ($tags === []) {
        $ungrouped[] = $name;
    }
    unset($app['groups']);
    $apps[$name] = $app;
}

/** @param list<string> $runFlags */
$emit = static function (string $status, array $names = [], string $label = '', string $reply = '', array $runFlags = []) use ($apps): never {
    echo json_encode([
        'status' => $status,
        'apps_csv' => implode(',', $names),
        'label' => $label,
        'flags' => implode(' ', $runFlags),
        'matrix' => ['include' => array_map(static fn(string $n): array => $apps[$n], $names)],
        'reply' => $reply,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
};

$code = static fn(array $tokens): string => implode(', ', array_map(static fn(string $t): string => "`{$t}`", $tokens));

$comment = $argc > 1 ? '/psalm-delta ' . $argv[1] : (string) getenv('COMMENT_BODY');
$firstLine = strtolower(rtrim(explode("\n", $comment, 2)[0], "\r"));
$tokens = preg_split('/[\s,]+/', $firstLine, -1, PREG_SPLIT_NO_EMPTY) ?: [];
if (array_shift($tokens) !== '/psalm-delta') {
    $emit('ignore');
}

// Only a lone `help` counts: the workflow keeps only `/psalm-delta help` off the
// run concurrency key, so `help` mixed into a run comment is an error instead.
if ($tokens === ['help']) {
    $lines = [
        '**`/psalm-delta` usage**',
        '',
        '`/psalm-delta [token ...]` benchmarks the `default` group plus each token: a group tag or an app name, separated by spaces or commas. `/psalm-delta all` runs every app; `/psalm-delta help` shows this message.',
        '',
        'A token starting with `--` is a flag passed to `psalm-laravel analyze` on both sides of every selected app, e.g. `/psalm-delta blade --blade`. A flag the base plugin does not know crashes the base side.',
        '',
        '| Group | Description | Apps |',
        '|---|---|---|',
    ];
    foreach ($groups as $tag => $description) {
        $lines[] = "| `{$tag}` | {$description} | " . implode(', ', $members[$tag]) . ' |';
    }
    if ($ungrouped !== []) {
        $lines[] = '';
        $lines[] = 'Apps in no group (select by name or `all`): ' . implode(', ', $ungrouped) . '.';
    }
    if ($flags !== []) {
        $lines[] = '';
        $lines[] = '| Flag | Description |';
        $lines[] = '|---|---|';
        foreach ($flags as $flag => $description) {
            $lines[] = "| `{$flag}` | {$description} |";
        }
    }
    $emit('help', reply: implode("\n", $lines) . "\n");
}

$isFlag = static fn(string $token): bool => str_starts_with($token, '-');
$runFlags = array_values(array_filter($tokens, $isFlag));
$tokens = array_values(array_filter($tokens, static fn(string $token): bool => !$isFlag($token)));

$valid = [...array_keys($groups), ...array_keys($apps), 'all'];
$unknown = array_values(array_unique([...array_diff($tokens, $valid), ...array_diff($runFlags, array_keys($flags))]));
if ($unknown !== []) {
    $lines = [];
    // Capped so a huge comment can't push the reply past GitHub's 65,536-char limit.
    foreach (array_slice($unknown, 0, 10) as $token) {
        if ($token === 'help') {
            $lines[] = '- `help` works only on its own: `/psalm-delta help`';
            continue;
        }
        if ($isFlag($token)) {
            if (preg_match('/^--?[a-z0-9][a-z0-9_.=-]{0,39}$/', $token) !== 1) {
                $lines[] = '- a flag with characters outside `[a-z0-9_.=-]` (not echoed)';
                continue;
            }
            $candidates = array_keys($flags);
        } elseif (preg_match($safe, $token) !== 1) {
            $lines[] = '- a token with characters outside `[a-z0-9_-]` (not echoed)';
            continue;
        } else {
            $candidates = $valid;
        }
        $hint = '';
        if ($candidates !== []) {
            $distances = array_map(static fn(string $v): int => levenshtein($token, $v), $candidates);
            $best = (int) array_search(min($distances), $distances, true);
            $hint = $distances[$best] <= max(2, intdiv(strlen($token), 3)) ? " Did you mean `{$candidates[$best]}`?" : '';
        }
        $lines[] = "- `{$token}`{$hint}";
    }
    if (count($unknown) > 10) {
        $lines[] = sprintf('- and %d more', count($unknown) - 10);
    }
    $emit('error', reply: implode("\n", [
        '**`/psalm-delta`: unknown token, nothing was run.**',
        '',
        ...$lines,
        '',
        'Groups: ' . $code(array_keys($groups)) . '.',
        'Apps: ' . $code(array_keys($apps)) . '.',
        'Flags: ' . ($flags === [] ? 'none declared' : $code(array_keys($flags))) . '.',
        'Also: `all`, `help`.',
    ]) . "\n");
}

// Every flag here matched a declared key exactly, so it is a registry value.
if (in_array('all', $tokens, true)) {
    $emit('run', array_keys($apps), 'all', runFlags: $runFlags);
}

$picked = ['default' => true];
$selected = array_fill_keys($members['default'], true);
foreach ($tokens as $token) {
    $picked[$token] = true;
    foreach ($members[$token] ?? [$token] as $name) {
        $selected[$name] = true;
    }
}
$emit('run', array_keys(array_intersect_key($apps, $selected)), implode(' + ', array_keys($picked)), runFlags: $runFlags);
