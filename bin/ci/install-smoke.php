<?php

declare(strict_types=1);

/**
 * Cross-OS install/configuration smoke test. Follows the README quickstart
 * verbatim against a fresh Laravel project: create project, install the
 * plugin, `psalm-laravel init`, `analyze`, `diagnose`, optionally `add github`.
 *
 * PHP (not Bash) so the exact same script runs on Ubuntu, Windows, and macOS.
 * Subprocesses go through Symfony Process, which already solves the
 * Windows shim problem this script exists to guard against: Composer on
 * Windows is a `.bat`, and a native CreateProcess() call cannot exec a batch
 * file directly (the same class of bug fixed for `psalm-laravel analyze`
 * itself in #1189, where a bare `vendor/bin/psalm` shebang script failed the
 * same way).
 *
 * Usage:
 *   php bin/ci/install-smoke.php
 *   PLUGIN_SOURCE=packagist RUN_ADD_GITHUB=1 php bin/ci/install-smoke.php
 *   KEEP_APP=1 VERBOSE=1 php bin/ci/install-smoke.php
 *
 * Environment variables:
 *   PLUGIN_SOURCE       path|packagist (default: path)
 *   PLUGIN_PATH         Checkout to install in path mode (default: this repo's root)
 *   PLUGIN_CONSTRAINT   Version constraint passed to `composer require` (default:
 *                       "*@dev" in path mode, "^4.8" — matching README.md's Step 1 — in packagist mode)
 *   LARAVEL_VERSION     `laravel/laravel` installer version (default: latest stable)
 *   APP_DIR             Where to scaffold the throwaway app (default: a fresh temp dir)
 *   KEEP_APP            1 to keep the app dir even on success (default: 0; failures always preserve it)
 *   VERBOSE             1 to stream every subprocess's output live (default: 0; failures always show it)
 *   RUN_ADD_GITHUB      1 to also exercise `psalm-laravel add github` (default: 0)
 *
 * Exit codes: 0 = every step passed, 1 = a step failed (see the printed diagnostic block).
 */

require __DIR__ . '/../../vendor/autoload.php';

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

/** @var resource $GLOBALS['logHandle'] */
$GLOBALS['logHandle'] = null;

function logPath(): string
{
    return \getcwd() . \DIRECTORY_SEPARATOR . 'install-smoke.log';
}

function logLine(string $line): void
{
    $timestamped = \sprintf('%s %s', \gmdate('Y-m-d\TH:i:s\Z'), $line);
    \fwrite($GLOBALS['logHandle'], $timestamped . "\n");
    \fwrite(\STDOUT, $timestamped . "\n");
}

/** Verbose-only echo of subprocess output; always written to the log file regardless. */
function logOutput(string $label, string $output, string $errorOutput, bool $verbose): void
{
    $block = \sprintf("--- %s: stdout ---\n%s\n--- %s: stderr ---\n%s\n", $label, $output, $label, $errorOutput);
    \fwrite($GLOBALS['logHandle'], $block);
    if ($verbose) {
        \fwrite(\STDOUT, $block);
    }
}

// --- process execution ----------------------------------------------------

/**
 * @param list<string> $command
 * @param array<string, string> $extraEnv
 */
function runStep(string $label, array $command, string $cwd, array $extraEnv, bool $verbose): Process
{
    logLine(\sprintf('==> %s', $label));
    logLine(\sprintf('    $ %s (cwd: %s)', \implode(' ', $command), $cwd));

    $process = new Process($command, $cwd, $extraEnv === [] ? null : $extraEnv, null, STEP_TIMEOUT_SECONDS);
    $process->run();

    logOutput($label, $process->getOutput(), $process->getErrorOutput(), $verbose);

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
        \sprintf('Exit code: %s', $exitCode === null ? '(process could not start)' : (string) $exitCode),
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
    \fwrite($GLOBALS['logHandle'], $message . "\n");
    \fwrite(\STDERR, $message . "\n");

    exit(1);
}

// --- main ------------------------------------------------------------------

$pluginSource = envStr('PLUGIN_SOURCE', 'path');
if (!\in_array($pluginSource, ['path', 'packagist'], true)) {
    \fwrite(\STDERR, \sprintf("PLUGIN_SOURCE must be 'path' or 'packagist', got '%s'.\n", $pluginSource));
    exit(1);
}

$pluginPath = envStr('PLUGIN_PATH', \dirname(__DIR__, 2));
if ($pluginSource === 'path' && !\is_file($pluginPath . \DIRECTORY_SEPARATOR . 'composer.json')) {
    \fwrite(\STDERR, \sprintf("PLUGIN_PATH '%s' has no composer.json; nothing to install.\n", $pluginPath));
    exit(1);
}

// Packagist-mode default mirrors README.md's Step 1 exactly (`^4.8`, currently
// the beta 4.x/Psalm-7 line) — keep the two in sync when the README's pinned
// constraint moves. An unconstrained `require` is NOT equivalent: with
// prefer-stable it can resolve a numerically-higher but mis-tagged release
// instead of the README's intended target (e.g. v4.12.3 pulls Psalm 6, not 7).
$pluginConstraint = envStr('PLUGIN_CONSTRAINT', $pluginSource === 'path' ? '*@dev' : '^4.8');
$laravelVersion = envStr('LARAVEL_VERSION', '');
$keepApp = envBool('KEEP_APP', false);
$verbose = envBool('VERBOSE', false);
$runAddGithub = envBool('RUN_ADD_GITHUB', false);

$appDir = envStr('APP_DIR', '');
if ($appDir === '') {
    $appDir = \rtrim(\sys_get_temp_dir(), \DIRECTORY_SEPARATOR)
        . \DIRECTORY_SEPARATOR . 'psalm-install-smoke-' . \bin2hex(\random_bytes(4));
}

$GLOBALS['logHandle'] = \fopen(logPath(), 'a');
if ($GLOBALS['logHandle'] === false) {
    \fwrite(\STDERR, \sprintf("Could not open %s for writing.\n", logPath()));
    exit(1);
}

logLine(\sprintf(
    'Starting install smoke test: source=%s app_dir=%s laravel_version=%s',
    $pluginSource,
    $appDir,
    $laravelVersion === '' ? '(latest stable)' : $laravelVersion,
));

$psalmLaravelBin = static fn(string $dir): string => $dir . \DIRECTORY_SEPARATOR . 'vendor'
    . \DIRECTORY_SEPARATOR . 'bin' . \DIRECTORY_SEPARATOR . 'psalm-laravel';

$launchDir = \dirname($appDir);
if (!\is_dir($launchDir) && !@\mkdir($launchDir, 0755, true) && !\is_dir($launchDir)) {
    \fwrite(\STDERR, \sprintf("Could not create parent directory %s for the app dir.\n", $launchDir));
    exit(1);
}

// --- Step 1: fresh Laravel project -----------------------------------------

$createProjectCommand = ['composer', 'create-project', '--prefer-dist', '--no-interaction', '--no-ansi', 'laravel/laravel', $appDir];
if ($laravelVersion !== '') {
    $createProjectCommand[] = $laravelVersion;
}
$process = runStep('composer create-project', $createProjectCommand, $launchDir, [], $verbose);
if (!$process->isSuccessful()) {
    reportFailure('composer create-project', $createProjectCommand, $process->getExitCode(), $launchDir, $process->getOutput(), $process->getErrorOutput(), $appDir);
}

// --- Step 2: configure Composer exactly as the README's Step 1 instructs ---
//
// README: "Since Psalm 7.x is currently in beta, allow dev (or beta) packages
// first: `composer config minimum-stability dev && composer config
// prefer-stable true`". That applies regardless of install mode — path mode
// additionally registers the path repository so `require` resolves the
// current checkout instead of a published tag.

$configSteps = [['config', 'minimum-stability', 'dev'], ['config', 'prefer-stable', 'true']];
if ($pluginSource === 'path') {
    $repoJson = \json_encode(
        ['type' => 'path', 'url' => $pluginPath, 'options' => ['symlink' => false]],
        \JSON_THROW_ON_ERROR,
    );
    $configSteps[] = ['config', 'repositories.0', $repoJson];
}

foreach ($configSteps as $configArgs) {
    $command = ['composer', ...$configArgs];
    $process = runStep('composer ' . \implode(' ', $configArgs), $command, $appDir, [], $verbose);
    if (!$process->isSuccessful()) {
        reportFailure('composer config', $command, $process->getExitCode(), $appDir, $process->getOutput(), $process->getErrorOutput(), $appDir);
    }
}

// --- Step 3: install psalm/plugin-laravel, exactly as the README's `composer require --dev` step ---

$requirement = $pluginConstraint === '' ? 'psalm/plugin-laravel' : "psalm/plugin-laravel:{$pluginConstraint}";
$requireCommand = ['composer', 'require', '--dev', '--no-interaction', '--no-ansi', $requirement];
$process = runStep('composer require psalm/plugin-laravel', $requireCommand, $appDir, ['COMPOSER_MEMORY_LIMIT' => '-1'], $verbose);
if (!$process->isSuccessful()) {
    reportFailure('composer require psalm/plugin-laravel', $requireCommand, $process->getExitCode(), $appDir, $process->getOutput(), $process->getErrorOutput(), $appDir);
}

// --- Step 4: psalm-laravel init, and assert the generated config -----------

$initCommand = [\PHP_BINARY, $psalmLaravelBin($appDir), 'init', '--no-interaction'];
$process = runStep('psalm-laravel init', $initCommand, $appDir, [], $verbose);
if (!$process->isSuccessful()) {
    reportFailure('psalm-laravel init', $initCommand, $process->getExitCode(), $appDir, $process->getOutput(), $process->getErrorOutput(), $appDir);
}

$psalmXmlPath = $appDir . \DIRECTORY_SEPARATOR . 'psalm.xml';
if (!\is_file($psalmXmlPath)) {
    reportFailure('assert psalm.xml exists', [], null, $appDir, '', \sprintf('Expected %s to exist after `psalm-laravel init`.', $psalmXmlPath), $appDir);
}

$psalmXmlContents = \file_get_contents($psalmXmlPath);
if ($psalmXmlContents === false || !\str_contains($psalmXmlContents, 'Psalm\\LaravelPlugin\\Plugin')) {
    reportFailure('assert psalm.xml registers the plugin', [], null, $appDir, (string) $psalmXmlContents, 'Expected psalm.xml to reference Psalm\\LaravelPlugin\\Plugin.', $appDir);
}

// --- Step 5: psalm-laravel analyze ------------------------------------------

$analyzeCommand = [\PHP_BINARY, $psalmLaravelBin($appDir), 'analyze', '--no-cache', '--no-progress', '--output-format=compact'];
$process = runStep('psalm-laravel analyze', $analyzeCommand, $appDir, [], $verbose);
if (!$process->isSuccessful()) {
    reportFailure('psalm-laravel analyze', $analyzeCommand, $process->getExitCode(), $appDir, $process->getOutput(), $process->getErrorOutput(), $appDir);
}

// --- Step 6: psalm-laravel diagnose -----------------------------------------

$diagnoseCommand = [\PHP_BINARY, $psalmLaravelBin($appDir), 'diagnose', '--no-tips'];
$process = runStep('psalm-laravel diagnose', $diagnoseCommand, $appDir, [], $verbose);
// diagnose.txt is the single most useful artifact when triaging an install bug report,
// so it is saved standalone in addition to being folded into the main log.
\file_put_contents($appDir . \DIRECTORY_SEPARATOR . 'diagnose.txt', $process->getOutput() . $process->getErrorOutput());
if (!$process->isSuccessful()) {
    reportFailure('psalm-laravel diagnose', $diagnoseCommand, $process->getExitCode(), $appDir, $process->getOutput(), $process->getErrorOutput(), $appDir);
}

// --- Step 7 (optional): psalm-laravel add github ----------------------------

if ($runAddGithub) {
    $addGithubCommand = [\PHP_BINARY, $psalmLaravelBin($appDir), 'add', 'github', '--no-interaction'];
    $process = runStep('psalm-laravel add github', $addGithubCommand, $appDir, [], $verbose);
    if (!$process->isSuccessful()) {
        reportFailure('psalm-laravel add github', $addGithubCommand, $process->getExitCode(), $appDir, $process->getOutput(), $process->getErrorOutput(), $appDir);
    }

    $workflowPath = $appDir . \DIRECTORY_SEPARATOR . '.github' . \DIRECTORY_SEPARATOR . 'workflows' . \DIRECTORY_SEPARATOR . 'psalm.yml';
    if (!\is_file($workflowPath)) {
        reportFailure('assert .github/workflows/psalm.yml exists', [], null, $appDir, '', \sprintf('Expected %s to exist after `psalm-laravel add github`.', $workflowPath), $appDir);
    }
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
