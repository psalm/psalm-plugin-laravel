<?php

declare(strict_types=1);

/**
 * Cross-OS install/configuration smoke test. Follows the README quickstart
 * verbatim, installing the plugin from the current checkout and then running
 * `psalm-laravel init`, `analyze`, `diagnose` against a target project.
 *
 * Two target-project modes, selected by PROJECT_KIND (default `app`):
 *   - `app`     — a fresh `laravel/laravel` project. Exercises the Laravel-app
 *                 config path (`InitCommand::detectLaravelAppRoots()` and a
 *                 `bootstrap/app.php` plugin boot).
 *   - `package` — a real Laravel *package* checkout (default: laravel/socialite),
 *                 which autoloads from `src/` and ships no `artisan`. Exercises the
 *                 package config path (`InitCommand::detectPackageRoots()` and the
 *                 Orchestra Testbench plugin-boot fallback) that the app flow never
 *                 touches (#1198).
 *
 * PHP (not Bash) so the exact same script runs on Ubuntu, Windows, and macOS.
 * Subprocesses go through Symfony Process, which already solves the Windows
 * shim problem this script exists to guard against: Composer on Windows is a
 * `.bat`, and a native CreateProcess() call cannot exec a batch file directly
 * (the same class of bug fixed for `psalm-laravel analyze` itself in #1189,
 * where a bare `vendor/bin/psalm` shebang script failed the same way).
 *
 * The plugin is installed from the local checkout via a Composer path
 * repository. Verifying the published Packagist package is a separate concern,
 * tracked as a follow-up.
 *
 * Usage:
 *   php bin/ci/install-smoke.php
 *   PROJECT_KIND=package php bin/ci/install-smoke.php
 *   KEEP_APP=1 VERBOSE=1 php bin/ci/install-smoke.php
 *
 * Environment variables:
 *   PROJECT_KIND     `app` (default) or `package` — see the mode descriptions above
 *   PLUGIN_PATH      Checkout to install (default: this repo's root)
 *   LARAVEL_VERSION  `app` mode only: `laravel/laravel` installer version (default: latest stable)
 *   PACKAGE_REPO     `package` mode only: git URL to clone (default: laravel/socialite)
 *   PACKAGE_REF      `package` mode only: tag/branch to clone (default: a pinned socialite tag)
 *   PACKAGE_EXPECTED_ROOTS  `package` mode only: comma-separated dirs the generated psalm.xml must scan (default: src)
 *   APP_DIR          Where to scaffold the throwaway project (default: a fresh temp dir)
 *   KEEP_APP         1 to keep the app dir even on success (default: 0; failures always preserve it)
 *   VERBOSE          1 to stream every subprocess's output live (default: 0; failures always show it)
 *
 * Exit codes: 0 = every step passed, 1 = a step failed (see the printed diagnostic block).
 */

require __DIR__ . '/../../vendor/autoload.php';

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

const STEP_TIMEOUT_SECONDS = 300.0;

// --- env helpers --------------------------------------------------------

function envBool(string $name, bool $default): bool
{
    $value = \getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }

    return \in_array(\strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

function envStr(string $name, string $default): string
{
    $value = \getenv($name);
    return $value === false || $value === '' ? $default : $value;
}

// --- logging -------------------------------------------------------------

function logPath(): string
{
    return \getcwd() . \DIRECTORY_SEPARATOR . 'install-smoke.log';
}

/**
 * Lazily opens the log file once and caches the handle in a function-local
 * static. The inline `@var` keeps the handle typed as `resource` rather than
 * being widened to `mixed` on re-read.
 *
 * @return resource
 */
function logHandle()
{
    /** @var resource|null $handle */
    static $handle = null;
    if ($handle === null) {
        $opened = \fopen(logPath(), 'a');
        if ($opened === false) {
            \fwrite(\STDERR, \sprintf("Could not open %s for writing.\n", logPath()));
            exit(1);
        }

        $handle = $opened;
    }

    return $handle;
}

function logLine(string $line): void
{
    $timestamped = \sprintf('%s %s', \gmdate('Y-m-d\TH:i:s\Z'), $line);
    \fwrite(logHandle(), $timestamped . "\n");
    \fwrite(\STDOUT, $timestamped . "\n");
}

/** Verbose-only echo of subprocess output; always written to the log file regardless. */
function logOutput(string $label, string $output, string $errorOutput, bool $verbose): void
{
    $block = \sprintf("--- %s: stdout ---\n%s\n--- %s: stderr ---\n%s\n", $label, $output, $label, $errorOutput);
    \fwrite(logHandle(), $block);
    if ($verbose) {
        \fwrite(\STDOUT, $block);
    }
}

// --- process execution ----------------------------------------------------

/**
 * Runs a step to completion, logs it, and calls reportFailure() (which never
 * returns) if it didn't succeed — every call site can therefore treat a
 * returned Process as a guaranteed success.
 *
 * Symfony throws ProcessTimedOutException instead of returning a failed
 * Process when a step exceeds STEP_TIMEOUT_SECONDS (e.g. `composer
 * create-project` on a cold, slow Windows runner). Left uncaught, that
 * bypasses reportFailure() entirely — no diagnostic block, no preserved app
 * dir, and a bare PHP exit code of 255 instead of the documented 1 — for
 * exactly the transient failure mode this smoke test is most likely to hit
 * in real CI. Catching it here and routing it through the same
 * reportFailure() path keeps that guarantee for every step.
 *
 * @param list<string> $command
 * @param array<string, string> $extraEnv
 * @param list<int> $allowedExitCodes Exit codes treated as success. Defaults to [0];
 *        `package`-mode `analyze` passes [0, 2] because Psalm exits 2 when it ran to
 *        completion but found issues — expected for a real third-party package we do
 *        not own — while 1 (a launcher/config error, the #1189 class) still fails.
 */
function runStep(string $label, array $command, string $cwd, array $extraEnv, bool $verbose, string $appDir, array $allowedExitCodes = [0]): Process
{
    logLine(\sprintf('==> %s', $label));
    logLine(\sprintf('    $ %s (cwd: %s)', \implode(' ', $command), $cwd));

    $process = new Process($command, $cwd, $extraEnv === [] ? null : $extraEnv, null, STEP_TIMEOUT_SECONDS);

    try {
        $process->run();
    } catch (ProcessTimedOutException $timedOut) {
        // The process is still killed by Symfony before the exception is thrown;
        // its buffered output up to that point remains readable.
        $timedOutProcess = $timedOut->getProcess();
        logOutput($label, $timedOutProcess->getOutput(), $timedOutProcess->getErrorOutput(), $verbose);
        reportFailure(
            $label . ' (timed out)',
            $command,
            null,
            $cwd,
            $timedOutProcess->getOutput(),
            $timedOutProcess->getErrorOutput(),
            $appDir,
        );
    }

    logOutput($label, $process->getOutput(), $process->getErrorOutput(), $verbose);

    if (!\in_array($process->getExitCode(), $allowedExitCodes, true)) {
        reportFailure($label, $command, $process->getExitCode(), $cwd, $process->getOutput(), $process->getErrorOutput(), $appDir);
    }

    return $process;
}

/**
 * Prints the failed command, its exit code, working directory, and captured
 * output, preserves the app dir (by simply never reaching the cleanup call),
 * and exits non-zero. `$command` is empty for assertion-style failures
 * (e.g. "psalm.xml is missing") that never spawned a process.
 *
 * @param list<string> $command
 */
function reportFailure(
    string $label,
    array $command,
    ?int $exitCode,
    string $cwd,
    string $output,
    string $errorOutput,
    string $appDir,
): never {
    $lines = [
        '',
        '=== install-smoke FAILURE ===',
        \sprintf('Step: %s', $label),
        \sprintf('Command: %s', $command === [] ? '(none — assertion failure)' : \implode(' ', $command)),
        \sprintf('Working directory: %s', $cwd),
        // Null covers both "no process was ever spawned" (assertion failures)
        // and "spawned but timed out" — the label already says which.
        \sprintf('Exit code: %s', $exitCode === null ? '(none)' : (string) $exitCode),
        '--- stdout ---',
        $output,
        '--- stderr ---',
        $errorOutput,
        '==============================',
        \sprintf('App preserved at: %s', $appDir),
    ];

    // Written to the log file and STDERR directly (not logLine()) so a merged
    // stdout+stderr view — e.g. the GitHub Actions log — shows the block once.
    $message = \implode("\n", $lines);
    \fwrite(logHandle(), $message . "\n");
    \fwrite(\STDERR, $message . "\n");

    exit(1);
}

// --- main ------------------------------------------------------------------

$pluginPath = envStr('PLUGIN_PATH', \dirname(__DIR__, 2));
if (!\is_file($pluginPath . \DIRECTORY_SEPARATOR . 'composer.json')) {
    \fwrite(\STDERR, \sprintf("PLUGIN_PATH '%s' has no composer.json; nothing to install.\n", $pluginPath));
    exit(1);
}

$projectKind = \strtolower(envStr('PROJECT_KIND', 'app'));
if (!\in_array($projectKind, ['app', 'package'], true)) {
    \fwrite(\STDERR, \sprintf("PROJECT_KIND must be 'app' or 'package', got '%s'.\n", $projectKind));
    exit(1);
}

// `package` mode targets a real Laravel package checkout. Pinned to a released
// socialite tag (not a floating branch) so the run is reproducible and immune to
// mid-refactor breakage on socialite's default branch — and so socialite's own
// require-dev tree, pulled in when the plugin is required into it, stays frozen.
// Override either to exercise a different package or ref.
$packageRepo = envStr('PACKAGE_REPO', 'https://github.com/laravel/socialite.git');
$packageRef = envStr('PACKAGE_REF', 'v5.28.0');

// Directories the generated psalm.xml must scan in package mode, comma-separated.
// Defaults to socialite's `src`; override it alongside PACKAGE_REPO for a package
// that autoloads from a different or additional root (e.g. `lib`) so the assertion
// tracks the target rather than assuming socialite's layout. The app-only-marker
// negative assertions below are layout-independent and need no override.
$expectedRoots = \array_values(\array_filter(\array_map(
    'trim',
    \explode(',', envStr('PACKAGE_EXPECTED_ROOTS', 'src')),
)));

$laravelVersion = envStr('LARAVEL_VERSION', '');
$keepApp = envBool('KEEP_APP', false);
$verbose = envBool('VERBOSE', false);

$appDir = envStr('APP_DIR', '');
if ($appDir === '') {
    $appDir = \rtrim(\sys_get_temp_dir(), \DIRECTORY_SEPARATOR)
        . \DIRECTORY_SEPARATOR . 'psalm-install-smoke-' . \bin2hex(\random_bytes(4));
}

$targetSummary = $projectKind === 'package'
    ? \sprintf('package=%s@%s', $packageRepo, $packageRef)
    : \sprintf('laravel_version=%s', $laravelVersion === '' ? '(latest stable)' : $laravelVersion);
logLine(\sprintf('Starting install smoke test: kind=%s app_dir=%s %s', $projectKind, $appDir, $targetSummary));

$psalmLaravelBin = static fn(string $dir): string => $dir . \DIRECTORY_SEPARATOR . 'vendor'
    . \DIRECTORY_SEPARATOR . 'bin' . \DIRECTORY_SEPARATOR . 'psalm-laravel';

$launchDir = \dirname($appDir);
if (!\is_dir($launchDir) && !@\mkdir($launchDir, 0755, true) && !\is_dir($launchDir)) {
    \fwrite(\STDERR, \sprintf("Could not create parent directory %s for the app dir.\n", $launchDir));
    exit(1);
}

// --- Step 1: create the target project -------------------------------------
//
// `app` mode scaffolds a fresh Laravel application; `package` mode clones a real
// Laravel package (socialite by default). Both land at $appDir, and every later
// step (configure Composer, require the plugin, init/analyze/diagnose) is shared.

if ($projectKind === 'package') {
    // --depth 1 --branch <ref>: fetch only the pinned ref's tree, no history.
    $cloneCommand = ['git', 'clone', '--depth', '1', '--branch', $packageRef, $packageRepo, $appDir];
    runStep(\sprintf('git clone %s@%s', $packageRepo, $packageRef), $cloneCommand, $launchDir, [], $verbose, $appDir);
} else {
    // --no-blocking: a security advisory against any of laravel/laravel's transitive
    // deps (historically phpunit's dev range) would otherwise block the install with
    // a Composer policy error unrelated to this script — the same lesson already
    // applied in tests/Application/laravel-test.sh's identical create-project call.
    $createProjectCommand = ['composer', 'create-project', '--prefer-dist', '--no-interaction', '--no-ansi', '--no-blocking', 'laravel/laravel', $appDir];
    if ($laravelVersion !== '') {
        $createProjectCommand[] = $laravelVersion;
    }
    runStep('composer create-project', $createProjectCommand, $launchDir, [], $verbose, $appDir);
}

// --- Step 2: configure Composer exactly as the README's Step 1 instructs ---
//
// README: "Since Psalm 7.x is currently in beta, allow dev (or beta) packages
// first: `composer config minimum-stability dev && composer config
// prefer-stable true`". The path repository points `require` at the current
// checkout instead of a published tag.

$repoJson = \json_encode(
    ['type' => 'path', 'url' => $pluginPath, 'options' => ['symlink' => false]],
    \JSON_THROW_ON_ERROR,
);
$configSteps = [
    ['config', 'minimum-stability', 'dev'],
    ['config', 'prefer-stable', 'true'],
    ['config', 'repositories.0', $repoJson],
];
foreach ($configSteps as $configArgs) {
    $command = ['composer', ...$configArgs];
    runStep('composer ' . \implode(' ', $configArgs), $command, $appDir, [], $verbose, $appDir);
}

// --- Step 3: install psalm/plugin-laravel, exactly as the README's `composer require --dev` step ---

$requireCommand = ['composer', 'require', '--dev', '--no-interaction', '--no-ansi', '--no-blocking', 'psalm/plugin-laravel:*@dev'];
runStep('composer require psalm/plugin-laravel', $requireCommand, $appDir, ['COMPOSER_MEMORY_LIMIT' => '-1'], $verbose, $appDir);

// --- Step 4: psalm-laravel init, and assert the generated config -----------

$initCommand = [\PHP_BINARY, $psalmLaravelBin($appDir), 'init', '--no-interaction'];
runStep('psalm-laravel init', $initCommand, $appDir, [], $verbose, $appDir);

$psalmXmlPath = $appDir . \DIRECTORY_SEPARATOR . 'psalm.xml';
if (!\is_file($psalmXmlPath)) {
    reportFailure('assert psalm.xml exists', [], null, $appDir, '', \sprintf('Expected %s to exist after `psalm-laravel init`.', $psalmXmlPath), $appDir);
}

$psalmXmlContents = \file_get_contents($psalmXmlPath);
if ($psalmXmlContents === false || !\str_contains($psalmXmlContents, 'Psalm\\LaravelPlugin\\Plugin')) {
    reportFailure('assert psalm.xml registers the plugin', [], null, $appDir, (string) $psalmXmlContents, 'Expected psalm.xml to reference Psalm\\LaravelPlugin\\Plugin.', $appDir);
}

// Package mode only: prove `init` took the package branch (detectPackageRoots),
// not the Laravel-app branch. A package autoloads from its own root(s) — `src` for
// socialite, or whatever PACKAGE_EXPECTED_ROOTS names — and ships no artisan, so a
// correct package config scans each expected <directory name="..."/> and emits none
// of the app-only <file name="artisan"/> / <directory name="app"/> entries.
// Whitespace in the generated markup is fixed by InitCommand's template, so
// exact-substring matching is safe here.
if ($projectKind === 'package') {
    $contents = (string) $psalmXmlContents;
    foreach ($expectedRoots as $root) {
        $marker = \sprintf('<directory name="%s"/>', $root);
        if (!\str_contains($contents, $marker)) {
            reportFailure('assert psalm.xml scans the package root', [], null, $appDir, $contents, \sprintf('Expected package-mode psalm.xml to scan %s.', $marker), $appDir);
        }
    }

    foreach (['<file name="artisan"/>', '<directory name="app"/>'] as $appOnlyMarker) {
        if (\str_contains($contents, $appOnlyMarker)) {
            reportFailure('assert psalm.xml used the package (not Laravel-app) layout', [], null, $appDir, $contents, \sprintf('Package-mode psalm.xml unexpectedly contains the app-only marker %s.', $appOnlyMarker), $appDir);
        }
    }
}

// --- Step 5: psalm-laravel analyze ------------------------------------------

$analyzeCommand = [\PHP_BINARY, $psalmLaravelBin($appDir), 'analyze', '--no-cache', '--no-progress', '--output-format=compact'];
// app mode analyzes a pristine skeleton and must be issue-free (exit 0). package
// mode analyzes a real third-party package we do not own: Psalm exiting 2 means it
// ran to completion but found issues in *that package's* own code, which is not a
// plugin install failure — accept it, while still failing on 1 (a Psalm/launcher
// error, the #1189 class this smoke test exists to catch).
$analyzeAllowedExitCodes = $projectKind === 'package' ? [0, 2] : [0];
runStep('psalm-laravel analyze', $analyzeCommand, $appDir, [], $verbose, $appDir, $analyzeAllowedExitCodes);

// --- Step 6: psalm-laravel diagnose -----------------------------------------
//
// Output lands in install-smoke.log (and, with VERBOSE, on stdout); on failure
// reportFailure() prints it too. No separate artifact is written.

$diagnoseCommand = [\PHP_BINARY, $psalmLaravelBin($appDir), 'diagnose', '--no-tips'];
$diagnoseProcess = runStep('psalm-laravel diagnose', $diagnoseCommand, $appDir, [], $verbose, $appDir);

// Boot-mode differential. A package has no bootstrap/app.php, so the plugin must
// boot Laravel through the Orchestra Testbench fallback (branch 3 of
// ApplicationProvider); a fresh Laravel app must instead boot from its own
// bootstrap/app.php. `diagnose` reports the resolved mode ("Testbench fallback"
// is the only label containing "testbench", and only the fallback prints it), so
// asserting BOTH directions makes the two kinds mutually discriminating: it proves
// package mode exercises the Testbench path and guards the #766 silent-fallback
// case on the app side. Matched case-insensitively for robustness to the wording.
$bootedViaTestbench = \stripos($diagnoseProcess->getOutput(), 'testbench') !== false;
$expectsTestbench = $projectKind === 'package';
if ($bootedViaTestbench !== $expectsTestbench) {
    reportFailure(
        \sprintf('assert %s-mode boot path', $projectKind),
        [],
        null,
        $appDir,
        $diagnoseProcess->getOutput(),
        $expectsTestbench
            ? 'Expected `diagnose` to report the Testbench fallback boot mode for a package target.'
            : 'Expected `diagnose` to report a real bootstrap/app.php boot mode (not Testbench) for an app target.',
        $appDir,
    );
}

// --- Done --------------------------------------------------------------

logLine('All steps passed.');

if ($keepApp) {
    logLine(\sprintf('KEEP_APP=1: app preserved at %s', $appDir));
} else {
    (static function (string $dir): void {
        // Composer's own vendor/ trees can be deep; a plain scandir walk is
        // slower than needed for a directory we are about to discard entirely.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            $fileInfo->isDir() ? @\rmdir($fileInfo->getPathname()) : @\unlink($fileInfo->getPathname());
        }
        @\rmdir($dir);
    })($appDir);
}

exit(0);
