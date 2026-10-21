<?php

declare(strict_types=1);

/**
 * Checks `@todo-by <GitHub issue or PR URL> <what to remove>` markers: workarounds for an upstream bug that
 * must go once the upstream issue closes (convention in AGENTS.md, "Upstream first").
 *
 * Scans `git ls-files` under src, stubs, tests, docs, bin and .github, or only the files named after the flags.
 * A marker is the tag right behind `//`, `#` or a docblock `*`; one without a GitHub issue/PR URL and a
 * description is a format error.
 *
 * Usage: php bin/ci/check-todo-by.php [--offline] [file...]
 *   --offline  format check only, no network (safe for every PR)
 *   default    also asks the GitHub REST API (token from GITHUB_TOKEN or GH_TOKEN, optional) and reports every
 *              marker whose issue or PR is closed
 * Output: one `file:line <url> <state> <description>` line per finding, plus `::error` annotations when
 * GITHUB_ACTIONS=true.
 * Exit codes: 0 = clean, 1 = format error or closed upstream issue, 2 = API or git failure (never read as resolved).
 */

use PsalmLaravelPlugin\Ci\TodoBy\GitHubIssueStateResolver;
use PsalmLaravelPlugin\Ci\TodoBy\TodoByChecker;

require __DIR__ . '/todo-by/bootstrap.php';

$offline = false;
$files = [];
$explicit = false;
foreach (\array_slice($argv, 1) as $argument) {
    if ($argument === '--offline') {
        $offline = true;
    } elseif (\str_starts_with($argument, '-')) {
        \fwrite(\STDERR, "check-todo-by: unknown option {$argument}\n");
        exit(2);
    } else {
        $files[] = $argument;
        $explicit = true;
    }
}

$root = \dirname(__DIR__, 2);
if (!$explicit) {
    $listing = \shell_exec('git -C ' . \escapeshellarg($root) . ' ls-files -z');
    if (!\is_string($listing)) {
        \fwrite(\STDERR, "check-todo-by: `git ls-files` failed\n");
        exit(2);
    }

    $files = \array_values(\array_filter(\explode("\0", $listing), TodoByChecker::isScannable(...)));
}

$markers = [];
foreach ($files as $file) {
    $path = $explicit ? $file : "{$root}/{$file}";
    $contents = \is_file($path) ? \file_get_contents($path) : false;
    // A tracked-but-deleted file or a binary blob holds no markers.
    if ($contents === false || \str_contains($contents, "\0")) {
        continue;
    }

    \array_push($markers, ...TodoByChecker::parse($file, $contents));
}

$token = \getenv('GITHUB_TOKEN') ?: \getenv('GH_TOKEN') ?: null;
$report = TodoByChecker::check($markers, $offline ? null : (new GitHubIssueStateResolver($token))(...));

foreach ($report->lines() as $line) {
    echo $line, "\n";
}

if (\getenv('GITHUB_ACTIONS') === 'true') {
    foreach ($report->annotations() as $annotation) {
        echo $annotation, "\n";
    }
}

$scope = $offline
    ? 'format check only (--offline)'
    : "{$report->resolvedCount} upstream issue(s) queried via the GitHub API";
echo "Checked {$report->markerCount} @todo-by marker(s) in " . \count($files) . " file(s): {$scope}; " . ($report->exitCode() === 0 ? 'clean' : 'findings above') . ".\n";

exit($report->exitCode());
