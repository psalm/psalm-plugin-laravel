<?php

declare(strict_types=1);

/**
 * Resolve a `/psalm-delta` selector to the apps to benchmark.
 *
 * Usage:
 *   yq -o=json bin/ci/test-apps.yml | COMMENT_BODY='/psalm-delta octane' php select-apps.php
 *   yq -o=json bin/ci/test-apps.yml | php select-apps.php 'octane,vito'   # no /psalm-delta prefix
 *
 * Grammar (first line only, case-insensitive, tokens split on whitespace/commas):
 *   /psalm-delta            the `default` group
 *   /psalm-delta <tok>...   `default` plus each token: a group tag or an app name
 *                           (a group wins over an app of the same name)
 *   /psalm-delta all        every app
 *   /psalm-delta help       reply with groups and syntax, start no run
 *
 * Prints one JSON object: {status: run|help|error|ignore, apps_csv, label,
 * matrix: {include: [...]}, reply}. The comment body is untrusted input from a
 * privileged workflow, so every output except `reply` is built only from
 * registry values, and `reply` echoes a user token only when it is plain
 * [a-z0-9_-]. Exit 2 = malformed registry.
 */

$fail = static function (string $message): never {
    fwrite(STDERR, "select-apps: {$message}\n");
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

$emit = static function (string $status, array $names = [], string $label = '', string $reply = '') use ($apps): never {
    echo json_encode([
        'status' => $status,
        'apps_csv' => implode(',', $names),
        'label' => $label,
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

if (in_array('help', $tokens, true)) {
    $lines = [
        '**`/psalm-delta` usage**',
        '',
        '`/psalm-delta [token ...]` benchmarks the `default` group plus each token: a group tag or an app name, separated by spaces or commas. `/psalm-delta all` runs every app; `/psalm-delta help` shows this message.',
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
    $emit('help', reply: implode("\n", $lines) . "\n");
}

$valid = [...array_keys($groups), ...array_keys($apps), ...$reserved];
$unknown = array_values(array_unique(array_diff($tokens, $valid)));
if ($unknown !== []) {
    $lines = [];
    foreach ($unknown as $token) {
        if (preg_match($safe, $token) !== 1) {
            $lines[] = '- a token with characters outside `[a-z0-9_-]` (not echoed)';
            continue;
        }
        $distances = array_map(static fn(string $v): int => levenshtein($token, $v), $valid);
        $best = (int) array_search(min($distances), $distances, true);
        $hint = $distances[$best] <= max(2, intdiv(strlen($token), 3)) ? " Did you mean `{$valid[$best]}`?" : '';
        $lines[] = "- `{$token}`{$hint}";
    }
    $emit('error', reply: implode("\n", [
        '**`/psalm-delta`: unknown token, nothing was run.**',
        '',
        ...$lines,
        '',
        'Groups: ' . $code(array_keys($groups)) . '.',
        'Apps: ' . $code(array_keys($apps)) . '.',
        'Also: `all`, `help`.',
    ]) . "\n");
}

if (in_array('all', $tokens, true)) {
    $emit('run', array_keys($apps), 'all');
}

$picked = ['default' => true];
$selected = array_fill_keys($members['default'], true);
foreach ($tokens as $token) {
    $picked[$token] = true;
    foreach ($members[$token] ?? [$token] as $name) {
        $selected[$name] = true;
    }
}
$emit('run', array_keys(array_intersect_key($apps, $selected)), implode(' + ', array_keys($picked)));
